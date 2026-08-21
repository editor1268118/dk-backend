<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreServiceBookingRequest;
use App\Models\BookingReview;
use App\Models\Notification;
use App\Models\PlatformService;
use App\Models\ServiceBooking;
use App\Models\BookingVendorOffer;
use App\Services\VendorMatchingService;
use App\Services\CancellationPolicyService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceBookingController extends Controller
{
    protected VendorMatchingService $vendorMatchingService;
    protected CancellationPolicyService $cancellationPolicyService;
    protected NotificationService $notificationService;

    public function __construct(
        VendorMatchingService $vendorMatchingService,
        CancellationPolicyService $cancellationPolicyService,
        NotificationService $notificationService
    ) {
        $this->vendorMatchingService = $vendorMatchingService;
        $this->cancellationPolicyService = $cancellationPolicyService;
        $this->notificationService = $notificationService;
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

        // 5. Phase 11: Send notifications (booking created)
        try {
            $serviceName = $platformService->name;
            $notifOptions = [
                'entity_type' => 'service_booking',
                'entity_id'   => $booking->id,
                'data'        => ['booking_reference' => $booking->booking_reference, 'service_name' => $serviceName],
            ];

            // Notify customer
            $this->notificationService->notifyUser(
                $user->id,
                'Service booking created',
                "Your service booking #{$booking->booking_reference} for {$serviceName} has been created.",
                Notification::TYPE_SERVICE_BOOKING_CREATED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
            );

            // Notify admins
            $this->notificationService->notifyAdmins(
                'New service booking',
                "A new service booking #{$booking->booking_reference} for {$serviceName} has been created.",
                Notification::TYPE_SERVICE_BOOKING_CREATED,
                array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=bookings'])
            );
        } catch (\Exception $e) {
            // Never fail core action
        }

        // 6. Refresh to get updated status
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
     * Phase 6: Added OTP/job lifecycle fields to response.
     * Phase 7: Added payout/review fields and can_submit_review flag.
     */
    public function myBookings(Request $request)
    {
        $userId = $request->user()->id;

        $bookings = ServiceBooking::with([
                'platformService.category',
                'assignedProvider',
                'review',
            ])
            ->where('user_id', $userId)
            ->orderBy('preferred_date', 'desc')
            ->orderBy('preferred_time', 'desc')
            ->get()
            ->map(function ($booking) use ($userId) {
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
                    // Phase 6: OTP & Job lifecycle fields
                    'otp_required' => $booking->otp_required,
                    'start_otp_generated_at' => $booking->start_otp_generated_at,
                    'job_started_at' => $booking->job_started_at,
                    'job_completed_at' => $booking->job_completed_at,
                    'no_start_marked_at' => $booking->no_start_marked_at,
                    // Phase 7: Payout & Review fields
                    'payout_status' => $booking->payout_status,
                    'payout_released_at' => $booking->payout_released_at,
                    'can_submit_review' => $booking->canSubmitReview($userId),
                    'review' => $booking->review ? [
                        'id' => $booking->review->id,
                        'rating' => $booking->review->rating,
                        'comment' => $booking->review->comment,
                        'created_at' => $booking->review->created_at,
                    ] : null,
                    // Phase 8: Cancel, Reschedule, Issue fields
                    'can_cancel' => $booking->canCustomerCancel(),
                    'can_reschedule' => $booking->canCustomerReschedule(),
                    'can_report_issue' => $booking->canReportIssue($userId),
                    'cancelled_at' => $booking->cancelled_at,
                    'cancelled_by' => $booking->cancelled_by,
                    'cancellation_reason' => $booking->cancellation_reason,
                    'cancellation_fee' => $booking->cancellation_fee,
                    'refund_amount' => $booking->refund_amount,
                    'cancellation_policy_snapshot' => $booking->cancellation_policy_snapshot ? json_decode($booking->cancellation_policy_snapshot) : null,
                    'reschedule_count' => $booking->reschedule_count,
                    'last_rescheduled_at' => $booking->last_rescheduled_at,
                    'original_preferred_date' => $booking->original_preferred_date ? $booking->original_preferred_date->format('Y-m-d') : null,
                    'original_preferred_time' => $booking->original_preferred_time,
                    'previous_assigned_provider_user_id' => $booking->previous_assigned_provider_user_id,
                    'reschedule_reconfirmation_deadline_at' => $booking->reschedule_reconfirmation_deadline_at,
                    'reschedule_reconfirmed_at' => $booking->reschedule_reconfirmed_at,
                    'issue_reported_at' => $booking->issue_reported_at,
                    'issue_status' => $booking->issue_status,
                    'issue_description' => $booking->issue_description,
                    'created_at' => $booking->created_at,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
        ], 200);
    }

    // ==========================================
    // PHASE 6: CUSTOMER OTP API
    // ==========================================

    /**
     * GET /api/bookings/{booking_id}/start-otp
     *
     * Return OTP for the authenticated customer's confirmed booking.
     * Only the booking owner can see OTP. Vendor must never receive OTP through API.
     */
    public function getStartOtp(Request $request, $bookingId)
    {
        $user = $request->user();

        // 1. Find booking and verify ownership
        $booking = ServiceBooking::with(['platformService', 'assignedProvider:id,name'])
            ->where('id', $bookingId)
            ->first();

        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        // 2. Verify booking belongs to authenticated user
        if ($booking->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden. This booking does not belong to you.',
            ], 403);
        }

        // 3. Verify booking status is confirmed
        if ($booking->status !== ServiceBooking::STATUS_CONFIRMED) {
            return response()->json([
                'message' => 'OTP is only available for confirmed bookings. Current status: ' . $booking->status,
            ], 422);
        }

        // 4. If OTP not required for this service
        if (!$booking->otp_required) {
            return response()->json([
                'message' => 'OTP is not required for this service.',
                'booking_reference' => $booking->booking_reference,
                'otp_required' => false,
                'service_name' => $booking->platformService?->name,
            ], 200);
        }

        // 5. If OTP is required but missing (edge case: generate safely)
        if ($booking->otp_required && empty($booking->start_otp)) {
            $booking->generateStartOtp();
            $booking->refresh();
        }

        // 6. Return OTP details
        return response()->json([
            'message' => 'Start OTP retrieved successfully.',
            'booking_reference' => $booking->booking_reference,
            'otp_required' => true,
            'start_otp' => $booking->start_otp,
            'start_otp_generated_at' => $booking->start_otp_generated_at,
            'service_name' => $booking->platformService?->name,
            'assigned_provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
            ] : null,
        ], 200);
    }

    // ==========================================
    // PHASE 7: CUSTOMER SUBMIT REVIEW
    // ==========================================

    /**
     * POST /api/bookings/{booking_id}/submit-review
     *
     * Customer submits an optional review for a completed booking.
     * Review does NOT affect payout.
     */
    public function submitReview(Request $request, $bookingId)
    {
        $user = $request->user();

        // 1. Validate input
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        // 2. Find booking
        $booking = ServiceBooking::with('review')
            ->where('id', $bookingId)
            ->first();

        if (!$booking) {
            return response()->json([
                'message' => 'Booking not found.',
            ], 404);
        }

        // 3. Verify booking belongs to authenticated user
        if ($booking->user_id !== $user->id) {
            return response()->json([
                'message' => 'Forbidden. This booking does not belong to you.',
            ], 403);
        }

        // 4. Verify booking is completed (or legacy review_pending)
        if (!$booking->isCompleted()) {
            return response()->json([
                'message' => 'Review can only be submitted for completed bookings. Current status: ' . $booking->status,
            ], 422);
        }

        // 5. Check if review already exists
        if ($booking->review) {
            return response()->json([
                'message' => 'Review already submitted for this booking.',
            ], 422);
        }

        // 6. Create review
        $review = BookingReview::create([
            'booking_id' => $booking->id,
            'user_id' => $user->id,
            'provider_user_id' => $booking->assigned_provider_user_id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
            'is_visible' => true,
        ]);

        // 7. Update booking
        $booking->update([
            'customer_review_submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at,
            ],
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->status,
                'customer_review_submitted_at' => $booking->fresh()->customer_review_submitted_at,
            ],
        ], 201);
    }

    // ==========================================
    // PHASE 8: CANCELLATION, RESCHEDULE, ISSUES
    // ==========================================

    /**
     * GET /api/bookings/{booking_id}/cancellation-preview
     */
    public function cancellationPreview(Request $request, $bookingId)
    {
        $user = $request->user();
        $booking = ServiceBooking::where('id', $bookingId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if ($booking->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $preview = $this->cancellationPolicyService->getPreviewForBooking($booking);

        return response()->json(['cancellation_preview' => $preview], 200);
    }

    /**
     * POST /api/bookings/{booking_id}/cancel
     */
    public function cancelBooking(Request $request, $bookingId)
    {
        $user = $request->user();
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $booking = ServiceBooking::where('id', $bookingId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if ($booking->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (!$booking->canCustomerCancel()) {
            return response()->json(['message' => 'Booking cannot be cancelled in its current state.'], 422);
        }

        $policy = $this->cancellationPolicyService->calculateFee($booking);

        DB::transaction(function () use ($booking, $validated, $policy) {
            $booking->update([
                'status' => ServiceBooking::STATUS_CANCELLED_BY_USER,
                'cancelled_by' => 'customer',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['reason'],
                'cancellation_fee' => $policy['cancellation_fee'],
                'refund_amount' => $policy['refund_amount'],
                'cancellation_policy_snapshot' => json_encode([
                    'fee_percent' => $policy['cancellation_fee_percent'],
                    'label' => $policy['policy_label'],
                ]),
            ]);

            BookingVendorOffer::where('booking_id', $booking->id)
                ->whereIn('status', [BookingVendorOffer::STATUS_SENT, BookingVendorOffer::STATUS_ACCEPTED])
                ->update(['status' => BookingVendorOffer::STATUS_CANCELLED]);
        });

        return response()->json([
            'message' => 'Booking cancelled successfully.',
            'booking' => $booking->fresh(),
            'cancellation_summary' => $policy,
        ], 200);
    }

    /**
     * POST /api/bookings/{booking_id}/reschedule
     *
     * Customer reschedules their own booking.
     * Resets provider assignment, clears OTP/job fields, expires old offers,
     * sets status to broadcasted, and rebroadcasts to matching vendors.
     */
    public function rescheduleBooking(Request $request, $bookingId)
    {
        $user = $request->user();

        $validated = $request->validate([
            'preferred_date' => 'required|date|after:today|before_or_equal:' . now()->addDays(365)->format('Y-m-d'),
            'preferred_time' => 'required|string|max:100',
            'reason'         => 'nullable|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($bookingId, $user, $validated) {
                $booking = ServiceBooking::where('id', $bookingId)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    return ['success' => false, 'message' => 'Booking not found.', 'code' => 404];
                }

                // Must be the booking owner
                if ($booking->user_id !== $user->id) {
                    return ['success' => false, 'message' => 'Forbidden. This booking does not belong to you.', 'code' => 403];
                }

                // Status check
                if (!$booking->canCustomerReschedule()) {
                    if ($booking->reschedule_count >= ServiceBooking::MAX_RESCHEDULES) {
                        return ['success' => false, 'message' => 'Maximum reschedule limit (3) reached.', 'code' => 422];
                    }
                    return ['success' => false, 'message' => 'Booking cannot be rescheduled in its current status: ' . $booking->status, 'code' => 422];
                }

                // Validate new date/time is in the future
                $newDateTime = \Carbon\Carbon::parse($validated['preferred_date'] . ' ' . $validated['preferred_time']);
                if ($newDateTime->lte(now())) {
                    return ['success' => false, 'message' => 'New date and time must be in the future.', 'code' => 422];
                }

                // Save original date/time only on first reschedule
                $updateData = [];
                if ($booking->reschedule_count === 0) {
                    $updateData['original_preferred_date'] = $booking->preferred_date;
                    $updateData['original_preferred_time'] = $booking->preferred_time;
                }

                // If booking is already assigned to a professional
                if ($booking->assigned_provider_user_id) {
                    $updateData = array_merge($updateData, [
                        'preferred_date'                        => $validated['preferred_date'],
                        'preferred_time'                        => $validated['preferred_time'],
                        'reschedule_count'                      => $booking->reschedule_count + 1,
                        'last_rescheduled_at'                   => now(),
                        'reschedule_reason'                     => $validated['reason'] ?? null,
                        'status'                                => ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION,
                        'previous_assigned_provider_user_id'    => $booking->assigned_provider_user_id,
                        // Keep assigned_provider_user_id intact
                        'reschedule_reconfirmation_deadline_at' => now()->addMinutes(15),
                        'vendor_accepted_at'                    => null,
                        'action_window_ends_at'                 => null,
                        'confirmed_at'                          => null,
                        // Clear OTP & job fields
                        'start_otp'                             => null,
                        'start_otp_generated_at'                => null,
                        'start_otp_verified_at'                 => null,
                        'job_started_at'                        => null,
                    ]);
                } else {
                    // Expire/cancel old vendor offers for this booking
                    BookingVendorOffer::where('booking_id', $booking->id)
                        ->whereIn('status', [
                            BookingVendorOffer::STATUS_SENT,
                            BookingVendorOffer::STATUS_ACCEPTED,
                        ])
                        ->update([
                            'status' => BookingVendorOffer::STATUS_EXPIRED,
                        ]);

                    // Update booking
                    $updateData = array_merge($updateData, [
                        'preferred_date'            => $validated['preferred_date'],
                        'preferred_time'            => $validated['preferred_time'],
                        'reschedule_count'          => $booking->reschedule_count + 1,
                        'last_rescheduled_at'       => now(),
                        'reschedule_reason'         => $validated['reason'] ?? null,
                        'status'                    => ServiceBooking::STATUS_BROADCASTED,
                        // Clear provider assignment
                        'assigned_provider_user_id' => null,
                        'vendor_accepted_at'        => null,
                        'action_window_ends_at'     => null,
                        'confirmed_at'              => null,
                        // Clear OTP & job fields
                        'start_otp'                 => null,
                        'start_otp_generated_at'    => null,
                        'start_otp_verified_at'     => null,
                        'job_started_at'            => null,
                    ]);
                }

                $booking->update($updateData);

                // Phase 11: Notify about reschedule
                try {
                    $notifOptions = [
                        'entity_type' => 'service_booking',
                        'entity_id'   => $booking->id,
                        'data'        => ['booking_reference' => $booking->booking_reference],
                    ];

                    // Notify assigned professional (if any)
                    if ($booking->assigned_provider_user_id) {
                        $this->notificationService->notifyProvider(
                            $booking->assigned_provider_user_id,
                            'Customer rescheduled booking',
                            "Customer has rescheduled booking #{$booking->booking_reference}. Please accept or reject the new time.",
                            Notification::TYPE_SERVICE_RESCHEDULE_REQUESTED,
                            array_merge($notifOptions, ['action_url' => '/dashboard?tab=bookings-received'])
                        );
                    }

                    // Notify admins
                    $this->notificationService->notifyAdmins(
                        'Customer rescheduled booking',
                        "Booking #{$booking->booking_reference} has been rescheduled by the customer.",
                        Notification::TYPE_SERVICE_RESCHEDULE_REQUESTED,
                        array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=bookings'])
                    );
                } catch (\Exception $e) {
                    // Never fail core action
                }

                return ['success' => true, 'booking' => $booking->fresh()];
            });

            if (!$result['success']) {
                return response()->json(['message' => $result['message']], $result['code']);
            }

            $booking = $result['booking'];

            $isRebroadcasting = ($booking->status === ServiceBooking::STATUS_BROADCASTED);

            if ($isRebroadcasting) {
                // Rebroadcast to matching vendors (outside transaction for safety)
                try {
                    $this->vendorMatchingService->broadcastToMatchingVendors($booking);
                } catch (\Exception $matchingEx) {
                    \Illuminate\Support\Facades\Log::warning(
                        "Failed to rebroadcast after reschedule for booking #{$booking->id}: " . $matchingEx->getMessage()
                    );
                }
            }

            $message = $isRebroadcasting
                ? 'Booking rescheduled successfully. We are searching for a professional again.'
                : 'Booking rescheduled successfully. Waiting for the professional to accept the new time.';

            return response()->json([
                'message' => $message,
                'booking' => [
                    'id'                      => $booking->id,
                    'booking_reference'       => $booking->booking_reference,
                    'status'                  => $booking->status,
                    'preferred_date'          => $booking->preferred_date ? $booking->preferred_date->format('Y-m-d') : null,
                    'preferred_time'          => $booking->preferred_time,
                    'reschedule_count'        => $booking->reschedule_count,
                    'last_rescheduled_at'     => $booking->last_rescheduled_at,
                    'original_preferred_date' => $booking->original_preferred_date ? $booking->original_preferred_date->format('Y-m-d') : null,
                    'original_preferred_time' => $booking->original_preferred_time,
                ],
            ], 200);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Reschedule booking error: " . $e->getMessage());
            return response()->json(['message' => 'An error occurred while rescheduling the booking.'], 500);
        }
    }

    /**
     * POST /api/bookings/{booking_id}/report-issue
     */
    public function reportIssue(Request $request, $bookingId)
    {
        $user = $request->user();
        $validated = $request->validate([
            'description' => 'required|string|max:2000',
        ]);

        $booking = ServiceBooking::where('id', $bookingId)->first();

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if ($booking->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (!$booking->canReportIssue($user->id)) {
            return response()->json(['message' => 'Issue cannot be reported for this booking.'], 422);
        }

        $booking->update([
            'issue_reported_at' => now(),
            'issue_status' => 'open',
            'issue_description' => $validated['description'],
        ]);

        // Phase 11: Notify about issue
        try {
            $notifOptions = [
                'priority'    => Notification::PRIORITY_HIGH,
                'entity_type' => 'service_booking',
                'entity_id'   => $booking->id,
                'data'        => ['booking_reference' => $booking->booking_reference],
            ];

            // Notify admins
            $this->notificationService->notifyAdmins(
                'New service issue reported',
                "An issue has been reported for booking #{$booking->booking_reference}.",
                Notification::TYPE_SERVICE_ISSUE_REPORTED,
                array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=issues'])
            );

            // Notify assigned professional (if any)
            if ($booking->assigned_provider_user_id) {
                $this->notificationService->notifyProvider(
                    $booking->assigned_provider_user_id,
                    'New service issue reported',
                    "An issue has been reported for booking #{$booking->booking_reference}.",
                    Notification::TYPE_SERVICE_ISSUE_REPORTED,
                    array_merge($notifOptions, ['action_url' => '/dashboard?tab=bookings-received'])
                );
            }
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => 'Issue reported successfully. Our support team will review it.',
            'booking' => $booking->fresh(),
        ], 200);
    }

    /**
     * POST /api/system/bookings/expire-reschedule-reconfirmations
     *
     * Finds bookings stuck in pending confirmation where deadline has passed.
     * Clears provider, sets to broadcasted, expires old offers, and rebroadcasts.
     */
    public function expireRescheduleReconfirmations(Request $request)
    {
        $expiredBookings = ServiceBooking::where('status', ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION)
            ->where('reschedule_reconfirmation_deadline_at', '<=', now())
            ->get();

        $processedCount = 0;

        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking) {
                // Expire old offers
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->whereIn('status', [
                        BookingVendorOffer::STATUS_SENT,
                        BookingVendorOffer::STATUS_ACCEPTED,
                    ])
                    ->update([
                        'status' => BookingVendorOffer::STATUS_EXPIRED,
                    ]);

                $booking->update([
                    'status'                    => ServiceBooking::STATUS_BROADCASTED,
                    'assigned_provider_user_id' => null,
                    'vendor_accepted_at'        => null,
                    'action_window_ends_at'     => null,
                    'confirmed_at'              => null,
                ]);
            });

            // Rebroadcast
            try {
                $this->vendorMatchingService->broadcastToMatchingVendors($booking->fresh());
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to rebroadcast expired reconfirmation booking #{$booking->id}: " . $e->getMessage());
            }
            
            $processedCount++;
        }

        return response()->json([
            'message' => "Processed {$processedCount} expired reconfirmations.",
            'count'   => $processedCount
        ]);
    }
}
