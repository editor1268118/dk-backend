<?php

namespace App\Services;

use App\Models\BookingVendorOffer;
use App\Models\ProviderSelectedService;
use App\Models\ProviderServiceArea;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VendorMatchingService
{
    /**
     * Find eligible vendors for a given booking and broadcast job offers.
     *
     * @param ServiceBooking $booking
     * @return array{vendors_found: int, offers_created: int}
     */
    public function broadcastToMatchingVendors(ServiceBooking $booking): array
    {
        // Step 1: Find all matching vendors
        $matchingVendorIds = $this->findMatchingVendors($booking);

        // Step 2: Create offers for each matching vendor
        $offersCreated = 0;

        if ($matchingVendorIds->isNotEmpty()) {
            $offersCreated = $this->createOffers($booking, $matchingVendorIds);

            // Update booking status to broadcasted
            $booking->update(['status' => ServiceBooking::STATUS_BROADCASTED]);
        } else {
            // No vendors available
            $booking->update(['status' => ServiceBooking::STATUS_NO_VENDOR_AVAILABLE]);
        }

        return [
            'vendors_found' => $matchingVendorIds->count(),
            'offers_created' => $offersCreated,
        ];
    }

    /**
     * Find all vendors matching the booking criteria.
     *
     * A vendor is eligible if:
     * 1. Has provider role
     * 2. Has provider profile
     * 3. Provider profile is active (verified or at least not blocked)
     * 4. Has selected the platform service in provider_selected_services
     * 5. Service area matches booking location (city + state, or pincode)
     * 6. Provider is not blocked/risk-blocked (user status check)
     *
     * @param ServiceBooking $booking
     * @return Collection<int> Collection of eligible provider user IDs
     */
    public function findMatchingVendors(ServiceBooking $booking): Collection
    {
        // Start with providers who have selected this platform service and it's active
        $providersWithService = ProviderSelectedService::where('platform_service_id', $booking->platform_service_id)
            ->where('is_active', true)
            ->pluck('provider_user_id');

        if ($providersWithService->isEmpty()) {
            return collect();
        }

        // Filter by location match
        $providersInArea = $this->filterByLocation(
            $providersWithService,
            $booking->service_country,
            $booking->service_state,
            $booking->service_city,
            $booking->service_pincode
        );

        if ($providersInArea->isEmpty()) {
            return collect();
        }

        // Filter by provider eligibility (role, profile, active status)
        $eligibleProviders = $this->filterByEligibility($providersInArea);

        return $eligibleProviders;
    }

    /**
     * Filter providers by matching service area location.
     *
     * Phase 2 matching: country, state, city, or pincode.
     *
     * @param Collection $providerIds
     * @param string|null $country
     * @param string|null $state
     * @param string|null $city
     * @param string|null $pincode
     * @return Collection<int>
     */
    protected function filterByLocation(
        Collection $providerIds,
        ?string $country,
        ?string $state,
        ?string $city,
        ?string $pincode
    ): Collection {
        $query = ProviderServiceArea::whereIn('provider_user_id', $providerIds)
            ->where('is_active', true);

        $query->where(function ($q) use ($country, $state, $city, $pincode) {
            if ($pincode) {
                $q->where('pincode', $pincode);
            }
            if ($city || $state || $country) {
                $q->orWhere(function ($sub) use ($city, $state, $country) {
                    if ($country) {
                        $sub->whereRaw('LOWER(country) LIKE ?', ['%' . strtolower($country) . '%']);
                    }
                    if ($state) {
                        $sub->whereRaw('LOWER(state) LIKE ?', ['%' . strtolower($state) . '%']);
                    }
                    if ($city) {
                        $sub->whereRaw('LOWER(city) LIKE ?', ['%' . strtolower($city) . '%']);
                    }
                });
            }
        });

        return $query->pluck('provider_user_id')->unique();
    }

    /**
     * Filter providers by eligibility criteria.
     *
     * Checks:
     * - User has provider role
     * - User has provider profile
     * - User is not blocked (status is active or null)
     *
     * @param Collection $providerIds
     * @return Collection<int>
     */
    protected function filterByEligibility(Collection $providerIds): Collection
    {
        return User::whereIn('id', $providerIds)
            ->whereHas('roles', function ($q) {
                $q->where('slug', 'provider');
            })
            ->whereHas('providerProfile')
            ->where(function ($q) {
                // Not blocked / risk-blocked
                $q->where('status', 'active')
                    ->orWhereNull('status');
            })
            ->pluck('id');
    }

    /**
     * Create booking vendor offers for matching providers.
     *
     * @param ServiceBooking $booking
     * @param Collection $providerIds
     * @return int Number of offers created
     */
    protected function createOffers(ServiceBooking $booking, Collection $providerIds): int
    {
        $now = now();
        $offersCreated = 0;
        $notifiedProviderIds = [];

        foreach ($providerIds as $providerId) {
            // Prevent duplicate offers (upsert-safe)
            $exists = BookingVendorOffer::where('booking_id', $booking->id)
                ->where('provider_user_id', $providerId)
                ->exists();

            if (!$exists) {
                BookingVendorOffer::create([
                    'booking_id' => $booking->id,
                    'provider_user_id' => $providerId,
                    'status' => BookingVendorOffer::STATUS_SENT,
                    'sent_at' => $now,
                ]);
                $offersCreated++;
                $notifiedProviderIds[] = $providerId;
            }
        }

        // Phase 11: Notify each matched vendor about the new job offer
        if (!empty($notifiedProviderIds)) {
            try {
                $notificationService = app(NotificationService::class);
                $serviceName = $booking->platformService?->name ?? 'Service';
                foreach ($notifiedProviderIds as $providerId) {
                    $notificationService->notifyProvider(
                        $providerId,
                        'New job offer',
                        "You have received a new service job offer for {$serviceName}.",
                        \App\Models\Notification::TYPE_SERVICE_JOB_OFFER_RECEIVED,
                        [
                            'entity_type' => 'service_booking',
                            'entity_id'   => $booking->id,
                            'action_url'  => '/dashboard?tab=job-offers',
                            'data'        => ['booking_reference' => $booking->booking_reference, 'service_name' => $serviceName],
                        ]
                    );
                }
            } catch (\Exception $e) {
                // Never fail core action
            }
        }

        return $offersCreated;
    }
}
