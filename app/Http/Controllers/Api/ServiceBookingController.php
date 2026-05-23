<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreServiceBookingRequest;
use App\Models\PlatformService;
use App\Models\ServiceBooking;
use App\Services\VendorMatchingService;
use Illuminate\Http\Request;

class ServiceBookingController extends Controller
{
    protected VendorMatchingService $vendorMatchingService;

    public function __construct(VendorMatchingService $vendorMatchingService)
    {
        $this->vendorMatchingService = $vendorMatchingService;
    }

    /**
     * POST /api/book-service
     *
     * Create a new service booking with price snapshot.
     * Mock payment success → broadcast to matching vendors.
     */
    public function store(StoreServiceBookingRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        // 1. Fetch the platform service and verify it's active
        $platformService = PlatformService::where('is_active', true)
            ->find($validated['platform_service_id']);

        if (!$platformService) {
            return response()->json([
                'message' => 'Platform service not found or inactive.',
            ], 404);
        }

        // 2. Capture price snapshot
        $customerPrice = (float) $platformService->customer_price;
        $vendorPayoutPercentage = (float) $platformService->vendor_payout_percentage;
        $vendorExpectedPayout = round($customerPrice * $vendorPayoutPercentage / 100, 2);
        $platformAmount = round($customerPrice - $vendorExpectedPayout, 2);

        // 3. Create the booking
        $booking = ServiceBooking::create([
            'booking_reference' => ServiceBooking::generateBookingReference(),
            'user_id' => $user->id,
            'platform_service_id' => $platformService->id,
            'customer_name' => $user->name,
            'customer_phone' => $validated['customer_phone'],
            'customer_email' => $user->email,
            'service_country' => $validated['service_country'] ?? null,
            'service_state' => $validated['service_state'] ?? null,
            'service_city' => $validated['service_city'] ?? null,
            'service_pincode' => $validated['service_pincode'] ?? null,
            'service_address_line_1' => $validated['service_address_line_1'] ?? null,
            'service_address_line_2' => $validated['service_address_line_2'] ?? null,
            'preferred_date' => $validated['preferred_date'],
            'preferred_time' => $validated['preferred_time'],
            'customer_price' => $customerPrice,
            'vendor_payout_percentage' => $vendorPayoutPercentage,
            'vendor_expected_payout' => $vendorExpectedPayout,
            'platform_amount' => $platformAmount,
            // Mock payment success for now
            'payment_status' => ServiceBooking::PAYMENT_PAID,
            'status' => ServiceBooking::STATUS_PAID,
            'notes' => $validated['notes'] ?? null,
        ]);

        // 4. Broadcast to matching vendors
        $broadcastResult = $this->vendorMatchingService->broadcastToMatchingVendors($booking);

        // 5. Refresh to get updated status
        $booking->refresh();
        $booking->load(['platformService.category', 'assignedProvider:id,name,email']);

        return response()->json([
            'message' => 'Booking created successfully.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'service' => [
                    'id' => $booking->platformService->id,
                    'name' => $booking->platformService->name,
                    'category' => $booking->platformService->category?->name,
                ],
                'customer_phone' => $booking->customer_phone,
                'preferred_date' => $booking->preferred_date->format('Y-m-d'),
                'preferred_time' => $booking->preferred_time,
                'location' => [
                    'country' => $booking->service_country,
                    'state' => $booking->service_state,
                    'city' => $booking->service_city,
                    'pincode' => $booking->service_pincode,
                    'address_line_1' => $booking->service_address_line_1,
                    'address_line_2' => $booking->service_address_line_2,
                ],
                'pricing' => [
                    'customer_price' => $booking->customer_price,
                    'vendor_payout_percentage' => $booking->vendor_payout_percentage,
                    'vendor_expected_payout' => $booking->vendor_expected_payout,
                    'platform_amount' => $booking->platform_amount,
                ],
                'payment_status' => $booking->payment_status,
                'status' => $booking->status,
                'notes' => $booking->notes,
                'assigned_provider' => $booking->assignedProvider,
                'created_at' => $booking->created_at,
            ],
            'broadcast' => [
                'vendors_matched' => $broadcastResult['vendors_found'],
                'offers_sent' => $broadcastResult['offers_created'],
            ],
        ], 201);
    }

    /**
     * GET /api/platform-services/{id}/booking-summary
     *
     * Show booking summary before user books.
     * Includes price, cancellation policy, reschedule rules.
     */
    public function bookingSummary($id)
    {
        $service = PlatformService::with('category:id,name,slug')
            ->where('is_active', true)
            ->find($id);

        if (!$service) {
            return response()->json([
                'message' => 'Platform service not found.',
            ], 404);
        }

        $customerPrice = (float) $service->customer_price;
        $vendorPayoutPercentage = (float) $service->vendor_payout_percentage;
        $vendorExpectedPayout = round($customerPrice * $vendorPayoutPercentage / 100, 2);
        $platformAmount = round($customerPrice - $vendorExpectedPayout, 2);

        return response()->json([
            'booking_summary' => [
                'service' => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'description' => $service->description,
                    'category' => $service->category,
                ],
                'pricing' => [
                    'customer_price' => $customerPrice,
                    'vendor_payout_percentage' => $vendorPayoutPercentage,
                    'vendor_expected_payout' => $vendorExpectedPayout,
                    'platform_amount' => $platformAmount,
                ],
                'required_fields' => [
                    'preferred_date' => 'Date (YYYY-MM-DD)',
                    'preferred_time' => 'Time (HH:MM)',
                    'service_address_line_1' => 'Service location address',
                ],
                'cancellation_policy' => [
                    'rules' => [
                        [
                            'window' => 'Before 24 hours',
                            'penalty_percentage' => 0,
                            'description' => 'Full refund if cancelled before 24 hours of service time.',
                        ],
                        [
                            'window' => '24–12 hours before',
                            'penalty_percentage' => 50,
                            'description' => '50% penalty if cancelled between 24 and 12 hours before service.',
                        ],
                        [
                            'window' => '12–3 hours before',
                            'penalty_percentage' => 80,
                            'description' => '80% penalty if cancelled between 12 and 3 hours before service.',
                        ],
                        [
                            'window' => '0–3 hours before',
                            'penalty_percentage' => 90,
                            'description' => '90% penalty if cancelled within 3 hours of service time.',
                        ],
                    ],
                    'note' => 'Cancellation penalties are not enforced yet. Will be implemented in a future phase.',
                ],
                'reschedule_policy' => [
                    'max_reschedules' => 3,
                    'allowed_until' => 'Up to 1 year from booking date',
                    'note' => 'Rescheduling is not enforced yet. Will be implemented in a future phase.',
                ],
            ],
        ], 200);
    }

    /**
     * GET /api/my/bookings
     *
     * Return authenticated user's service bookings.
     */
    public function myBookings(Request $request)
    {
        $bookings = ServiceBooking::with([
                'platformService.category',
                'assignedProvider',
            ])
            ->where('user_id', $request->user()->id)
            ->orderBy('preferred_date', 'desc')
            ->orderBy('preferred_time', 'desc')
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'service' => [
                        'id' => $booking->platformService?->id,
                        'name' => $booking->platformService?->name ?? 'Service',
                        'category' => $booking->platformService?->category?->name ?? 'General',
                    ],
                    'preferred_date' => $booking->preferred_date ? $booking->preferred_date->format('Y-m-d') : '',
                    'preferred_time' => $booking->preferred_time,
                    'customer_phone' => $booking->customer_phone,
                    'location' => [
                        'country' => $booking->service_country,
                        'state' => $booking->service_state,
                        'city' => $booking->service_city,
                        'pincode' => $booking->service_pincode,
                        'address_line_1' => $booking->service_address_line_1,
                        'address_line_2' => $booking->service_address_line_2,
                    ],
                    'customer_price' => $booking->customer_price,
                    'status' => $booking->status,
                    'payment_status' => $booking->payment_status,
                    'assigned_provider' => $booking->assignedProvider ? [
                        'id' => $booking->assignedProvider->id,
                        'name' => $booking->assignedProvider->name,
                        'email' => $booking->assignedProvider->email,
                    ] : null,
                    'vendor_accepted_at' => $booking->vendor_accepted_at,
                    'action_window_ends_at' => $booking->action_window_ends_at,
                    'confirmed_at' => $booking->confirmed_at,
                    'vendor_change_reason' => $booking->vendor_change_reason,
                    'created_at' => $booking->created_at,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ], 200);
    }
}
