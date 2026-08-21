<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingVendorOffer;
use App\Models\Notification;
use App\Models\ServiceBooking;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorJobOfferController extends Controller
{
    /**
     * GET /api/professional/job-offers
     *
     * List all pending job offers for the authenticated provider.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Verify provider role
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        $offers = BookingVendorOffer::with([
                'booking.platformService.category',
            ])
            ->where('provider_user_id', $user->id)
            ->where('status', BookingVendorOffer::STATUS_SENT)
            ->whereHas('booking', function ($q) {
                $q->where('status', ServiceBooking::STATUS_BROADCASTED);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($offer) {
                return [
                    'id' => $offer->id,
                    'offer_id' => $offer->id,
                    'offer_status' => $offer->status,
                    'status' => $offer->status,
                    'sent_at' => $offer->sent_at,
                    'booking_reference' => $offer->booking->booking_reference,
                    'service_name' => $offer->booking->platformService?->name ?? 'Service',
                    'customer_phone' => $offer->booking->customer_phone,
                    'customer_location' => [
                        'country' => $offer->booking->service_country,
                        'state' => $offer->booking->service_state,
                        'city' => $offer->booking->service_city,
                        'pincode' => $offer->booking->service_pincode,
                        'address' => $offer->booking->service_address_line_1,
                    ],
                    'preferred_date' => $offer->booking->preferred_date?->format('Y-m-d'),
                    'preferred_time' => $offer->booking->preferred_time,
                    'expected_payout' => $offer->booking->vendor_expected_payout,
                    'notes' => $offer->booking->notes,
                    'booking' => [
                        'id' => $offer->booking->id,
                        'booking_reference' => $offer->booking->booking_reference,
                        'service' => [
                            'id' => $offer->booking->platformService?->id,
                            'name' => $offer->booking->platformService?->name ?? 'Service',
                            'category' => $offer->booking->platformService?->category?->name ?? 'General',
                        ],
                        'customer_phone' => $offer->booking->customer_phone,
                        'preferred_date' => $offer->booking->preferred_date?->format('Y-m-d'),
                        'preferred_time' => $offer->booking->preferred_time,
                        'location' => [
                            'city' => $offer->booking->service_city,
                            'state' => $offer->booking->service_state,
                            'country' => $offer->booking->service_country,
                            'pincode' => $offer->booking->service_pincode,
                            'address' => $offer->booking->service_address_line_1,
                        ],
                        'vendor_expected_payout' => $offer->booking->vendor_expected_payout,
                        'notes' => $offer->booking->notes,
                        'booking_status' => $offer->booking->status,
                    ],
                ];
            });

        return response()->json([
            'job_offers' => $offers,
            'total' => $offers->count(),
        ], 200);
    }

    /**
     * POST /api/professional/job-offers/{id}/accept
     *
     * Accept a job offer with race-condition-safe database locking.
     *
     * Implementation:
     * 1. Wrap in DB transaction
     * 2. Lock the booking row (SELECT ... FOR UPDATE) to prevent race conditions
     * 3. Check if booking is already assigned → reject if so
     * 4. Assign the provider and mark offer as accepted
     */
    public function accept(Request $request, $id)
    {
        $user = $request->user();

        // Verify provider role
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        // Ensure provider wallet exists and check balance
        $walletService = new \App\Services\WalletService();
        $wallet = $walletService->getOrCreateWallet($user->id);

        if ((float) $wallet->balance < 50.00) {
            return response()->json([
                'message' => 'Minimum wallet balance of ₹50 is required to accept jobs. Please recharge your wallet.',
            ], 422);
        }

        // Find the offer
        $offer = BookingVendorOffer::where('id', $id)
            ->where('provider_user_id', $user->id)
            ->first();

        if (!$offer) {
            return response()->json([
                'message' => 'Job offer not found.',
            ], 404);
        }

        // Offer must be in 'sent' status to be accepted
        if ($offer->status !== BookingVendorOffer::STATUS_SENT) {
            return response()->json([
                'message' => 'This offer can no longer be accepted. Current status: ' . $offer->status,
            ], 422);
        }

        // ============================================================
        // RACE CONDITION LOCK
        // Use DB transaction + row-level lock on the booking
        // to ensure only the first vendor can accept.
        // ============================================================
        try {
            $result = DB::transaction(function () use ($offer, $user) {
                // Lock the booking row to prevent concurrent accepts
                $booking = ServiceBooking::where('id', $offer->booking_id)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    return ['success' => false, 'message' => 'Booking not found.', 'code' => 404];
                }

                // Check if booking is already assigned to another provider
                if ($booking->isAssigned()) {
                    return [
                        'success' => false,
                        'message' => 'Job already assigned to another provider.',
                        'code' => 409,
                    ];
                }

                // Check booking is in a valid state for acceptance
                if (!in_array($booking->status, [
                    ServiceBooking::STATUS_BROADCASTED,
                    ServiceBooking::STATUS_PAID,
                ])) {
                    return [
                        'success' => false,
                        'message' => 'This booking is no longer available for acceptance. Status: ' . $booking->status,
                        'code' => 422,
                    ];
                }

                $now = now();

                // Accept the offer
                $offer->update([
                    'status' => BookingVendorOffer::STATUS_ACCEPTED,
                    'accepted_at' => $now,
                ]);

                // Assign the provider to the booking
                $booking->update([
                    'assigned_provider_user_id' => $user->id,
                    'status' => ServiceBooking::STATUS_VENDOR_ACCEPTED,
                    'vendor_accepted_at' => $now,
                    'action_window_ends_at' => $now->copy()->addMinutes(5),
                ]);

                // Reject/cancel all other pending offers for this booking
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->where('id', '!=', $offer->id)
                    ->where('status', BookingVendorOffer::STATUS_SENT)
                    ->update([
                        'status' => BookingVendorOffer::STATUS_REJECTED,
                        'rejected_at' => $now,
                    ]);

                return ['success' => true, 'booking' => $booking->fresh()];
            });

            if (!$result['success']) {
                return response()->json([
                    'message' => $result['message'],
                ], $result['code']);
            }

            $booking = $result['booking'];
            $booking->load(['platformService.category']);

            // Phase 11: Notify customer + admins that vendor accepted
            try {
                $notificationService = app(NotificationService::class);
                $notifOptions = [
                    'entity_type' => 'service_booking',
                    'entity_id'   => $booking->id,
                    'data'        => ['booking_reference' => $booking->booking_reference, 'provider_name' => $user->name],
                ];

                // Notify customer
                $notificationService->notifyUser(
                    $booking->user_id,
                    'Professional accepted your booking',
                    "A professional has accepted your service booking #{$booking->booking_reference}.",
                    Notification::TYPE_SERVICE_VENDOR_ACCEPTED,
                    array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
                );

                // Notify admins
                $notificationService->notifyAdmins(
                    'Service booking accepted by professional',
                    "Booking #{$booking->booking_reference} has been accepted by {$user->name}.",
                    Notification::TYPE_SERVICE_VENDOR_ACCEPTED,
                    array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=bookings'])
                );
            } catch (\Exception $e) {
                // Never fail core action
            }

            return response()->json([
                'message' => 'Job offer accepted successfully. You are now assigned to this booking.',
                'booking' => [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'service' => [
                        'id' => $booking->platformService?->id,
                        'name' => $booking->platformService?->name ?? 'Service',
                        'category' => $booking->platformService?->category?->name ?? 'General',
                    ],
                    'customer_phone' => $booking->customer_phone,
                    'preferred_date' => $booking->preferred_date ? $booking->preferred_date->format('Y-m-d') : '',
                    'preferred_time' => $booking->preferred_time,
                    'location' => [
                        'city' => $booking->service_city,
                        'state' => $booking->service_state,
                        'address' => $booking->service_address_line_1,
                    ],
                    'vendor_expected_payout' => $booking->vendor_expected_payout,
                    'status' => $booking->status,
                    'vendor_accepted_at' => $booking->vendor_accepted_at,
                    'action_window_ends_at' => $booking->action_window_ends_at,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while accepting the offer. Please try again.',
            ], 500);
        }
    }

    /**
     * POST /api/professional/job-offers/{id}/reject
     *
     * Reject a job offer.
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();

        // Verify provider role
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        // Find the offer
        $offer = BookingVendorOffer::where('id', $id)
            ->where('provider_user_id', $user->id)
            ->first();

        if (!$offer) {
            return response()->json([
                'message' => 'Job offer not found.',
            ], 404);
        }

        // Offer must be in 'sent' status to be rejected
        if ($offer->status !== BookingVendorOffer::STATUS_SENT) {
            return response()->json([
                'message' => 'This offer can no longer be rejected. Current status: ' . $offer->status,
            ], 422);
        }

        $offer->update([
            'status' => BookingVendorOffer::STATUS_REJECTED,
            'rejected_at' => now(),
        ]);

        return response()->json([
            'message' => 'Job offer rejected.',
            'offer' => [
                'id' => $offer->id,
                'status' => $offer->status,
                'rejected_at' => $offer->rejected_at,
            ],
        ], 200);
    }
}
