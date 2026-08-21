<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\BookingVendorOffer;
use App\Models\ProfessionalWallet;
use App\Models\ProfessionalWalletTransaction;
use App\Models\ServiceBooking;
use App\Services\VendorMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9: Admin Support Dashboard Controller
 *
 * All routes are protected by auth:sanctum + admin middleware.
 * No real payment gateway refunds, no real bank payouts, no SMS/email notifications.
 */
class AdminSupportController extends Controller
{
    protected VendorMatchingService $vendorMatchingService;

    public function __construct(VendorMatchingService $vendorMatchingService)
    {
        $this->vendorMatchingService = $vendorMatchingService;
    }

    // =========================================================================
    // B) DASHBOARD OVERVIEW
    // =========================================================================

    /**
     * GET /api/admin/support/overview
     *
     * Returns high-level summary cards for the admin support dashboard.
     */
    public function overview(Request $request)
    {
        // --- Booking-level aggregates ---
        $totalBookings    = ServiceBooking::count();
        $todayBookings    = ServiceBooking::whereDate('created_at', today())->count();
        $activeBookings   = ServiceBooking::whereIn('status', [
            ServiceBooking::STATUS_BROADCASTED,
            ServiceBooking::STATUS_VENDOR_ACCEPTED,
            ServiceBooking::STATUS_CONFIRMED,
            ServiceBooking::STATUS_IN_PROGRESS,
            ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION,
        ])->count();
        $completedBookings       = ServiceBooking::where('status', ServiceBooking::STATUS_COMPLETED)->count();
        $cancelledByUser         = ServiceBooking::where('status', ServiceBooking::STATUS_CANCELLED_BY_USER)->count();
        $cancelledByVendor       = ServiceBooking::where('status', ServiceBooking::STATUS_CANCELLED_BY_VENDOR)->count();
        $issueReportedCount      = ServiceBooking::whereNotNull('issue_reported_at')->count();
        $openIssuesCount         = ServiceBooking::where('issue_status', 'open')->count();
        $failedNoStartCount      = ServiceBooking::where('status', ServiceBooking::STATUS_FAILED_NO_START)->count();

        // --- Financial aggregates ---
        $totalCustomerValue       = ServiceBooking::sum('customer_price');
        $totalVendorPayoutReleased = ServiceBooking::where('payout_status', ServiceBooking::PAYOUT_RELEASED)
            ->sum('vendor_expected_payout');
        $totalPlatformAmount      = ServiceBooking::sum('platform_amount');
        $totalVendorPenalties     = ProfessionalWalletTransaction::where('type', 'penalty_debit')->sum('amount');

        // --- Wallet health ---
        $negativeWalletVendors = ProfessionalWallet::where('balance', '<', 0)->count();

        // --- Pending reschedule reconfirmations ---
        $pendingReschedules = ServiceBooking::where('status', ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION)->count();

        // --- Status breakdown ---
        $statusCounts = ServiceBooking::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return response()->json([
            'overview' => [
                'total_bookings'                        => $totalBookings,
                'today_bookings'                        => $todayBookings,
                'active_bookings'                       => $activeBookings,
                'completed_bookings'                    => $completedBookings,
                'cancelled_by_user_count'               => $cancelledByUser,
                'cancelled_by_vendor_count'             => $cancelledByVendor,
                'issue_reported_count'                  => $issueReportedCount,
                'open_issues_count'                     => $openIssuesCount,
                'failed_no_start_count'                 => $failedNoStartCount,
                'total_customer_value'                  => round((float) $totalCustomerValue, 2),
                'total_vendor_payout_released'          => round((float) $totalVendorPayoutReleased, 2),
                'total_platform_amount'                 => round((float) $totalPlatformAmount, 2),
                'total_vendor_penalties'                => round((float) $totalVendorPenalties, 2),
                'negative_wallet_vendors_count'         => $negativeWalletVendors,
                'pending_reschedule_reconfirmations_count' => $pendingReschedules,
            ],
            'status_breakdown' => $statusCounts,
        ], 200);
    }

    // =========================================================================
    // C) ALL BOOKINGS
    // =========================================================================

    /**
     * GET /api/admin/support/bookings
     *
     * Returns a paginated list of all service bookings with rich filter support.
     *
     * Filters: status, payment_status, payout_status, service_id,
     *          provider_user_id, user_id, date_from, date_to, search,
     *          issue_status, wallet_negative, rescheduled_only
     */
    public function bookings(Request $request)
    {
        $query = ServiceBooking::with([
            'platformService:id,name,service_category_id',
            'platformService.category:id,name',
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
        ]);

        // --- Filters ---
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }

        if ($request->filled('payout_status')) {
            $query->where('payout_status', $request->payout_status);
        }

        if ($request->filled('service_id')) {
            $query->where('platform_service_id', $request->service_id);
        }

        if ($request->filled('provider_user_id')) {
            $query->where('assigned_provider_user_id', $request->provider_user_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('preferred_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('preferred_date', '<=', $request->date_to);
        }

        if ($request->filled('issue_status')) {
            $query->where('issue_status', $request->issue_status);
        }

        if ($request->boolean('wallet_negative')) {
            // Filter bookings where the assigned provider has a negative wallet
            $negativeProviderIds = ProfessionalWallet::where('balance', '<', 0)
                ->pluck('provider_user_id');
            $query->whereIn('assigned_provider_user_id', $negativeProviderIds);
        }

        if ($request->boolean('rescheduled_only')) {
            $query->where('reschedule_count', '>', 0);
        }

        // --- Search ---
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search)
                  ->orWhere('customer_email', 'like', $search)
                  ->orWhereHas('assignedProvider', function ($pq) use ($search) {
                      $pq->where('name', 'like', $search)
                         ->orWhere('phone', 'like', $search);
                  })
                  ->orWhereHas('platformService', function ($sq) use ($search) {
                      $sq->where('name', 'like', $search);
                  });
            });
        }

        $bookings = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        $bookings->getCollection()->transform(function ($booking) {
            return [
                'id'                     => $booking->id,
                'booking_reference'      => $booking->booking_reference,
                'customer' => [
                    'id'    => $booking->user?->id,
                    'name'  => $booking->customer_name,
                    'phone' => $booking->customer_phone,
                    'email' => $booking->customer_email,
                ],
                'service' => [
                    'id'       => $booking->platformService?->id,
                    'name'     => $booking->platformService?->name,
                    'category' => $booking->platformService?->category?->name,
                ],
                'preferred_date'         => $booking->preferred_date?->format('Y-m-d'),
                'preferred_time'         => $booking->preferred_time,
                'status'                 => $booking->status,
                'payment_status'         => $booking->payment_status,
                'payout_status'          => $booking->payout_status,
                'customer_price'         => (float) $booking->customer_price,
                'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
                'platform_amount'        => (float) $booking->platform_amount,
                'assigned_provider' => $booking->assignedProvider ? [
                    'id'    => $booking->assignedProvider->id,
                    'name'  => $booking->assignedProvider->name,
                    'phone' => $booking->assignedProvider->phone,
                    'email' => $booking->assignedProvider->email,
                ] : null,
                'reschedule_count'       => $booking->reschedule_count,
                'cancellation_fee'       => (float) $booking->cancellation_fee,
                'refund_amount'          => (float) $booking->refund_amount,
                'issue_status'           => $booking->issue_status,
                'created_at'             => $booking->created_at,
            ];
        });

        return response()->json([
            'bookings' => $bookings,
        ], 200);
    }

    // =========================================================================
    // D) BOOKING DETAIL
    // =========================================================================

    /**
     * GET /api/admin/support/bookings/{booking_id}
     *
     * Returns full booking detail including timeline, offers, wallet transactions,
     * review, cancellation, reschedule, and issue report.
     */
    public function bookingDetail(Request $request, $bookingId)
    {
        $booking = ServiceBooking::with([
            'user:id,name,phone,email',
            'platformService.category:id,name',
            'assignedProvider:id,name,phone,email',
            'vendorOffers.providerUser:id,name,phone,email',
            'walletTransactions',
            'review',
        ])->find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        // Build timeline from available timestamps
        $timeline = [];
        $tsMap = [
            'created_at'                        => 'Booking created',
            'vendor_accepted_at'                => 'Vendor accepted',
            'action_window_ends_at'             => 'Action window ends',
            'confirmed_at'                      => 'Booking confirmed',
            'start_otp_generated_at'            => 'Start OTP generated',
            'job_started_at'                    => 'Job started',
            'job_completed_at'                  => 'Job completed',
            'payout_released_at'                => 'Payout released',
            'cancelled_at'                      => 'Booking cancelled',
            'last_rescheduled_at'               => 'Last rescheduled',
            'issue_reported_at'                 => 'Issue reported',
            'issue_resolved_at'                 => 'Issue resolved',
            'reschedule_reconfirmation_deadline_at' => 'Reschedule reconfirmation deadline',
            'reschedule_reconfirmed_at'         => 'Reschedule reconfirmed by provider',
        ];

        foreach ($tsMap as $field => $label) {
            if (!empty($booking->$field)) {
                $timeline[] = [
                    'event'      => $label,
                    'field'      => $field,
                    'timestamp'  => $booking->$field,
                ];
            }
        }

        // Sort timeline by timestamp asc
        usort($timeline, fn($a, $b) => strtotime($a['timestamp']) <=> strtotime($b['timestamp']));

        return response()->json([
            'booking' => [
                // 1. Booking info
                'id'                     => $booking->id,
                'booking_reference'      => $booking->booking_reference,
                'status'                 => $booking->status,
                'payment_status'         => $booking->payment_status,
                'payout_status'          => $booking->payout_status,
                'preferred_date'         => $booking->preferred_date?->format('Y-m-d'),
                'preferred_time'         => $booking->preferred_time,
                'notes'                  => $booking->notes,
                'created_at'             => $booking->created_at,

                // 2. Customer details
                'customer' => [
                    'id'    => $booking->user?->id,
                    'name'  => $booking->customer_name,
                    'phone' => $booking->customer_phone,
                    'email' => $booking->customer_email,
                ],

                // 3. Assigned provider details
                'assigned_provider' => $booking->assignedProvider ? [
                    'id'    => $booking->assignedProvider->id,
                    'name'  => $booking->assignedProvider->name,
                    'phone' => $booking->assignedProvider->phone,
                    'email' => $booking->assignedProvider->email,
                ] : null,

                // 4. Platform service details
                'service' => [
                    'id'                     => $booking->platformService?->id,
                    'name'                   => $booking->platformService?->name,
                    'category'               => $booking->platformService?->category?->name,
                    'customer_price'         => (float) $booking->customer_price,
                    'vendor_payout_percentage' => (float) $booking->vendor_payout_percentage,
                    'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
                    'platform_amount'        => (float) $booking->platform_amount,
                    'payout_release_reference' => $booking->payout_release_reference,
                ],

                // 5. Booking timeline
                'timeline' => $timeline,

                // 6. Vendor offers
                'vendor_offers' => $booking->vendorOffers->map(fn($offer) => [
                    'id'           => $offer->id,
                    'provider'     => $offer->providerUser ? [
                        'id'    => $offer->providerUser->id,
                        'name'  => $offer->providerUser->name,
                        'phone' => $offer->providerUser->phone,
                        'email' => $offer->providerUser->email,
                    ] : null,
                    'status'       => $offer->status,
                    'created_at'   => $offer->created_at,
                    'responded_at' => $offer->responded_at ?? null,
                ]),

                // 7. Wallet transactions related to this booking
                'wallet_transactions' => $booking->walletTransactions->map(fn($tx) => [
                    'id'             => $tx->id,
                    'type'           => $tx->type,
                    'direction'      => $tx->direction,
                    'amount'         => (float) $tx->amount,
                    'description'    => $tx->description,
                    'balance_before' => (float) $tx->balance_before,
                    'balance_after'  => (float) $tx->balance_after,
                    'reference'      => $tx->reference,
                    'created_at'     => $tx->created_at,
                ]),

                // 8. Review
                'review' => $booking->review ? [
                    'id'         => $booking->review->id,
                    'rating'     => $booking->review->rating,
                    'comment'    => $booking->review->comment,
                    'is_visible' => $booking->review->is_visible,
                    'created_at' => $booking->review->created_at,
                ] : null,

                // 9. Cancellation details
                'cancellation' => [
                    'cancelled_by'       => $booking->cancelled_by,
                    'cancelled_at'       => $booking->cancelled_at,
                    'cancellation_reason' => $booking->cancellation_reason,
                    'cancellation_fee'   => (float) $booking->cancellation_fee,
                    'refund_amount'      => (float) $booking->refund_amount,
                    'policy_snapshot'    => $booking->cancellation_policy_snapshot
                        ? json_decode($booking->cancellation_policy_snapshot)
                        : null,
                ],

                // 10. Reschedule details
                'reschedule' => [
                    'reschedule_count'                  => $booking->reschedule_count,
                    'last_rescheduled_at'               => $booking->last_rescheduled_at,
                    'original_preferred_date'           => $booking->original_preferred_date?->format('Y-m-d'),
                    'original_preferred_time'           => $booking->original_preferred_time,
                    'reschedule_reason'                 => $booking->reschedule_reason ?? null,
                    'previous_assigned_provider_user_id' => $booking->previous_assigned_provider_user_id,
                    'reschedule_reconfirmation_deadline_at' => $booking->reschedule_reconfirmation_deadline_at,
                    'reschedule_reconfirmed_at'         => $booking->reschedule_reconfirmed_at,
                ],

                // 11. Issue report details
                'issue' => [
                    'issue_reported_at'    => $booking->issue_reported_at,
                    'issue_status'         => $booking->issue_status,
                    'issue_description'    => $booking->issue_description,
                    'issue_resolution_note' => $booking->issue_resolution_note ?? null,
                    'issue_resolved_at'    => $booking->issue_resolved_at ?? null,
                    'issue_resolved_by'    => $booking->issue_resolved_by ?? null,
                ],
            ],
        ], 200);
    }

    // =========================================================================
    // E) ISSUE MANAGEMENT
    // =========================================================================

    /**
     * GET /api/admin/support/issues
     *
     * Returns a filtered list of bookings with reported issues.
     */
    public function issues(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
        ])->whereNotNull('issue_reported_at');

        if ($request->filled('status')) {
            $query->where('issue_status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('issue_reported_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('issue_reported_at', '<=', $request->date_to);
        }

        if ($request->filled('provider_user_id')) {
            $query->where('assigned_provider_user_id', $request->provider_user_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search)
                  ->orWhere('customer_email', 'like', $search)
                  ->orWhere('issue_description', 'like', $search)
                  ->orWhereHas('assignedProvider', fn($pq) => $pq->where('name', 'like', $search));
            });
        }

        $issues = $query->orderBy('issue_reported_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        $issues->getCollection()->transform(fn($booking) => [
            'id'               => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'customer' => [
                'id'    => $booking->user?->id,
                'name'  => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
            ],
            'provider' => $booking->assignedProvider ? [
                'id'    => $booking->assignedProvider->id,
                'name'  => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
                'email' => $booking->assignedProvider->email,
            ] : null,
            'service'          => $booking->platformService?->name,
            'issue_status'     => $booking->issue_status,
            'issue_description' => $booking->issue_description,
            'issue_reported_at' => $booking->issue_reported_at,
            'issue_resolution_note' => $booking->issue_resolution_note ?? null,
            'issue_resolved_at'     => $booking->issue_resolved_at ?? null,
            'booking_status'   => $booking->status,
            'payout_status'    => $booking->payout_status,
        ]);

        return response()->json(['issues' => $issues], 200);
    }

    /**
     * POST /api/admin/support/bookings/{booking_id}/resolve-issue
     *
     * Marks an issue as resolved. Saves resolution note and admin ID.
     */
    public function resolveIssue(Request $request, $bookingId)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'resolution_note' => 'required|string|max:2000',
        ]);

        $booking = ServiceBooking::find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if (!$booking->issue_reported_at) {
            return response()->json(['message' => 'No issue has been reported for this booking.'], 422);
        }

        if (!in_array($booking->issue_status, ['open', null])) {
            return response()->json(['message' => 'Issue is already ' . $booking->issue_status . '.'], 422);
        }

        $booking->update([
            'issue_status'          => 'resolved',
            'issue_resolution_note' => $validated['resolution_note'],
            'issue_resolved_at'     => now(),
            'issue_resolved_by'     => $admin->id,
        ]);

        AdminActionLog::record(
            $admin->id,
            'issue_resolved',
            'service_booking',
            $booking->id,
            $validated['resolution_note'],
            ['booking_reference' => $booking->booking_reference]
        );

        // Phase 11: Notify customer + professional about issue resolution
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'service_booking',
                'entity_id'   => $booking->id,
                'data'        => ['booking_reference' => $booking->booking_reference, 'resolution' => 'resolved'],
            ];

            $notificationService->notifyUser(
                $booking->user_id,
                'Service issue updated',
                "Your reported issue for booking #{$booking->booking_reference} has been resolved.",
                \App\Models\Notification::TYPE_SERVICE_ISSUE_RESOLVED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
            );

            if ($booking->assigned_provider_user_id) {
                $notificationService->notifyProvider(
                    $booking->assigned_provider_user_id,
                    'Service issue updated',
                    "Issue for booking #{$booking->booking_reference} has been resolved by admin.",
                    \App\Models\Notification::TYPE_SERVICE_ISSUE_RESOLVED,
                    array_merge($notifOptions, ['action_url' => '/dashboard?tab=bookings-received'])
                );
            }
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => 'Issue resolved successfully.',
            'booking' => [
                'id'                    => $booking->id,
                'booking_reference'     => $booking->booking_reference,
                'issue_status'          => $booking->fresh()->issue_status,
                'issue_resolution_note' => $booking->fresh()->issue_resolution_note,
                'issue_resolved_at'     => $booking->fresh()->issue_resolved_at,
                'issue_resolved_by'     => $booking->fresh()->issue_resolved_by,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/support/bookings/{booking_id}/reject-issue
     *
     * Marks an issue as rejected. Payout is NOT reversed in this phase.
     */
    public function rejectIssue(Request $request, $bookingId)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'resolution_note' => 'required|string|max:2000',
        ]);

        $booking = ServiceBooking::find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        if (!$booking->issue_reported_at) {
            return response()->json(['message' => 'No issue has been reported for this booking.'], 422);
        }

        if (!in_array($booking->issue_status, ['open', null])) {
            return response()->json(['message' => 'Issue is already ' . $booking->issue_status . '.'], 422);
        }

        $booking->update([
            'issue_status'          => 'rejected',
            'issue_resolution_note' => $validated['resolution_note'],
            'issue_resolved_at'     => now(),
            'issue_resolved_by'     => $admin->id,
        ]);

        AdminActionLog::record(
            $admin->id,
            'issue_rejected',
            'service_booking',
            $booking->id,
            $validated['resolution_note'],
            ['booking_reference' => $booking->booking_reference]
        );

        // Phase 11: Notify customer + professional about issue rejection
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'service_booking',
                'entity_id'   => $booking->id,
                'data'        => ['booking_reference' => $booking->booking_reference, 'resolution' => 'rejected'],
            ];

            $notificationService->notifyUser(
                $booking->user_id,
                'Service issue updated',
                "Your reported issue for booking #{$booking->booking_reference} has been reviewed and rejected.",
                \App\Models\Notification::TYPE_SERVICE_ISSUE_RESOLVED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
            );

            if ($booking->assigned_provider_user_id) {
                $notificationService->notifyProvider(
                    $booking->assigned_provider_user_id,
                    'Service issue updated',
                    "Issue for booking #{$booking->booking_reference} has been rejected by admin.",
                    \App\Models\Notification::TYPE_SERVICE_ISSUE_RESOLVED,
                    array_merge($notifOptions, ['action_url' => '/dashboard?tab=bookings-received'])
                );
            }
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => 'Issue rejected. Payout reversal is not performed in this phase.',
            'booking' => [
                'id'                    => $booking->id,
                'booking_reference'     => $booking->booking_reference,
                'issue_status'          => $booking->fresh()->issue_status,
                'issue_resolution_note' => $booking->fresh()->issue_resolution_note,
                'issue_resolved_at'     => $booking->fresh()->issue_resolved_at,
                'issue_resolved_by'     => $booking->fresh()->issue_resolved_by,
            ],
        ], 200);
    }

    // =========================================================================
    // F) CANCELLATION / REFUND LEDGER
    // =========================================================================

    /**
     * GET /api/admin/support/cancellations
     *
     * Returns all cancelled bookings (mock refund ledger — no real refund processing).
     */
    public function cancellations(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
        ])->whereIn('status', [
            ServiceBooking::STATUS_CANCELLED_BY_USER,
            ServiceBooking::STATUS_CANCELLED_BY_VENDOR,
        ]);

        if ($request->filled('cancelled_by')) {
            $query->where('cancelled_by', $request->cancelled_by);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('cancelled_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('cancelled_at', '<=', $request->date_to);
        }

        if ($request->filled('service_id')) {
            $query->where('platform_service_id', $request->service_id);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search)
                  ->orWhere('customer_email', 'like', $search);
            });
        }

        $cancellations = $query->orderBy('cancelled_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        $cancellations->getCollection()->transform(fn($booking) => [
            'id'                 => $booking->id,
            'booking_reference'  => $booking->booking_reference,
            'cancelled_by'       => $booking->cancelled_by,
            'cancelled_at'       => $booking->cancelled_at,
            'cancellation_reason' => $booking->cancellation_reason,
            'cancellation_fee'   => (float) $booking->cancellation_fee,
            'refund_amount'      => (float) $booking->refund_amount,
            'customer_price'     => (float) $booking->customer_price,
            'service'            => $booking->platformService?->name,
            'customer' => [
                'id'    => $booking->user?->id,
                'name'  => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
            ],
            'provider' => $booking->assignedProvider ? [
                'id'    => $booking->assignedProvider->id,
                'name'  => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
                'email' => $booking->assignedProvider->email,
            ] : null,
            'status'             => $booking->status,
            'refund_note'        => 'Mock ledger only — no real refund processed.',
        ]);

        return response()->json(['cancellations' => $cancellations], 200);
    }

    // =========================================================================
    // G) RESCHEDULE MONITORING
    // =========================================================================

    /**
     * GET /api/admin/support/reschedules
     *
     * Returns bookings with reschedule_count > 0 OR status = reschedule_pending_provider_confirmation.
     */
    public function reschedules(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
        ])->where(function ($q) {
            $q->where('reschedule_count', '>', 0)
              ->orWhere('status', ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION);
        });

        $reschedules = $query->orderBy('last_rescheduled_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        $reschedules->getCollection()->transform(fn($booking) => [
            'id'                => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'customer' => [
                'id'    => $booking->user?->id,
                'name'  => $booking->customer_name,
                'phone' => $booking->customer_phone,
            ],
            'provider' => $booking->assignedProvider ? [
                'id'    => $booking->assignedProvider->id,
                'name'  => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
            ] : null,
            'service'                   => $booking->platformService?->name,
            'original_preferred_date'   => $booking->original_preferred_date?->format('Y-m-d'),
            'original_preferred_time'   => $booking->original_preferred_time,
            'current_preferred_date'    => $booking->preferred_date?->format('Y-m-d'),
            'current_preferred_time'    => $booking->preferred_time,
            'reschedule_count'          => $booking->reschedule_count,
            'last_rescheduled_at'       => $booking->last_rescheduled_at,
            'reschedule_reason'         => $booking->reschedule_reason ?? null,
            'reschedule_reconfirmation_deadline_at' => $booking->reschedule_reconfirmation_deadline_at,
            'reschedule_reconfirmed_at' => $booking->reschedule_reconfirmed_at,
            'status'                    => $booking->status,
        ]);

        return response()->json(['reschedules' => $reschedules], 200);
    }

    /**
     * POST /api/admin/support/reschedules/expire-pending
     *
     * Expires all reschedule reconfirmations whose deadline has passed,
     * clears the assigned provider, and rebroadcasts to matching vendors.
     */
    public function expirePendingReschedules(Request $request)
    {
        $admin = $request->user();

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
                    ->update(['status' => BookingVendorOffer::STATUS_EXPIRED]);

                $booking->update([
                    'status'                    => ServiceBooking::STATUS_BROADCASTED,
                    'assigned_provider_user_id' => null,
                    'vendor_accepted_at'        => null,
                    'action_window_ends_at'     => null,
                    'confirmed_at'              => null,
                ]);
            });

            // Rebroadcast to matching vendors
            try {
                $this->vendorMatchingService->broadcastToMatchingVendors($booking->fresh());
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning(
                    "Admin: failed to rebroadcast expired reconfirmation booking #{$booking->id}: " . $e->getMessage()
                );
            }

            $processedCount++;
        }

        // Log the admin action
        AdminActionLog::record(
            $admin->id,
            'reschedule_expired',
            'service_booking',
            0, // no single entity — bulk action
            "Admin manually expired {$processedCount} pending reschedule reconfirmations.",
            ['count' => $processedCount]
        );

        return response()->json([
            'message' => "Expired and rebroadcasted {$processedCount} pending reschedule reconfirmation(s).",
            'count'   => $processedCount,
        ], 200);
    }

    // =========================================================================
    // H) WALLET + PENALTY MONITORING
    // =========================================================================

    /**
     * GET /api/admin/support/wallets
     *
     * Returns provider wallets with aggregated transaction stats.
     */
    public function wallets(Request $request)
    {
        $query = ProfessionalWallet::with(['providerUser:id,name,phone,email']);

        if ($request->boolean('negative_only')) {
            $query->where('balance', '<', 0);
        }

        if ($request->filled('provider_user_id')) {
            $query->where('provider_user_id', $request->provider_user_id);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->whereHas('providerUser', fn($q) => $q
                ->where('name', 'like', $search)
                ->orWhere('phone', 'like', $search)
                ->orWhere('email', 'like', $search)
            );
        }

        $wallets = $query->orderBy('balance', 'asc')
            ->paginate($request->integer('per_page', 20));

        // Aggregate stats per wallet
        $wallets->getCollection()->transform(function ($wallet) {
            $txStats = ProfessionalWalletTransaction::where('wallet_id', $wallet->id)
                ->selectRaw("
                    SUM(CASE WHEN type = 'recharge' THEN amount ELSE 0 END) as total_recharges,
                    SUM(CASE WHEN type = 'penalty_debit' THEN amount ELSE 0 END) as total_penalties,
                    SUM(CASE WHEN type = 'payout_credit' THEN amount ELSE 0 END) as total_payouts,
                    MAX(created_at) as last_transaction_at
                ")
                ->first();

            return [
                'provider_user_id'   => $wallet->provider_user_id,
                'provider' => [
                    'id'    => $wallet->providerUser?->id,
                    'name'  => $wallet->providerUser?->name,
                    'phone' => $wallet->providerUser?->phone,
                    'email' => $wallet->providerUser?->email,
                ],
                'balance'            => (float) $wallet->balance,
                'currency'           => $wallet->currency,
                'is_active'          => $wallet->is_active,
                'total_recharges'    => round((float) ($txStats->total_recharges ?? 0), 2),
                'total_penalties'    => round((float) ($txStats->total_penalties ?? 0), 2),
                'total_payouts'      => round((float) ($txStats->total_payouts ?? 0), 2),
                'last_transaction_at' => $txStats->last_transaction_at,
            ];
        });

        return response()->json(['wallets' => $wallets], 200);
    }

    /**
     * GET /api/admin/support/wallets/{provider_user_id}/transactions
     *
     * Returns full transaction history for a provider's wallet.
     */
    public function walletTransactions(Request $request, $providerUserId)
    {
        $wallet = ProfessionalWallet::where('provider_user_id', $providerUserId)
            ->with('providerUser:id,name,phone,email')
            ->first();

        if (!$wallet) {
            return response()->json(['message' => 'Wallet not found for this provider.'], 404);
        }

        $query = ProfessionalWalletTransaction::where('wallet_id', $wallet->id)
            ->with('booking:id,booking_reference');

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 30));

        $transactions->getCollection()->transform(fn($tx) => [
            'id'               => $tx->id,
            'date'             => $tx->created_at,
            'booking_reference' => $tx->booking?->booking_reference ?? null,
            'type'             => $tx->type,
            'direction'        => $tx->direction,
            'amount'           => (float) $tx->amount,
            'description'      => $tx->description,
            'balance_before'   => (float) $tx->balance_before,
            'balance_after'    => (float) $tx->balance_after,
            'reference'        => $tx->reference,
        ]);

        return response()->json([
            'provider' => [
                'id'    => $wallet->providerUser?->id,
                'name'  => $wallet->providerUser?->name,
                'phone' => $wallet->providerUser?->phone,
                'email' => $wallet->providerUser?->email,
            ],
            'wallet' => [
                'balance'  => (float) $wallet->balance,
                'currency' => $wallet->currency,
                'is_active' => $wallet->is_active,
            ],
            'transactions' => $transactions,
        ], 200);
    }

    // =========================================================================
    // I) PAYOUT LEDGER
    // =========================================================================

    /**
     * GET /api/admin/support/payouts
     *
     * Returns payout ledger — bookings with an explicit payout_status.
     * No real bank payout is processed.
     */
    public function payouts(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
            'walletTransactions' => fn($q) => $q->where('type', 'payout_credit'),
        ])->whereNotNull('payout_status');

        if ($request->filled('payout_status')) {
            $query->where('payout_status', $request->payout_status);
        }

        if ($request->filled('provider_user_id')) {
            $query->where('assigned_provider_user_id', $request->provider_user_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('payout_released_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('payout_released_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhereHas('assignedProvider', fn($pq) => $pq->where('name', 'like', $search));
            });
        }

        $payouts = $query->orderBy('payout_released_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        $payouts->getCollection()->transform(fn($booking) => [
            'id'                     => $booking->id,
            'booking_reference'      => $booking->booking_reference,
            'provider' => $booking->assignedProvider ? [
                'id'    => $booking->assignedProvider->id,
                'name'  => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
                'email' => $booking->assignedProvider->email,
            ] : null,
            'customer' => [
                'id'    => $booking->user?->id,
                'name'  => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
            ],
            'service'                => $booking->platformService?->name,
            'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
            'payout_status'          => $booking->payout_status,
            'payout_released_at'     => $booking->payout_released_at,
            'payout_release_reference' => $booking->payout_release_reference,
            'wallet_transaction_reference' => $booking->walletTransactions->first()?->reference ?? null,
            'booking_status'         => $booking->status,
        ]);

        return response()->json(['payouts' => $payouts], 200);
    }

    // =========================================================================
    // J) VENDOR RISK ASSESSMENT
    // =========================================================================

    /**
     * GET /api/admin/support/vendor-risk
     *
     * Calculates a simple risk score per provider and returns sorted by highest risk.
     *
     * Risk scoring:
     *   +2 per vendor cancellation
     *   +2 per failed_no_start
     *   +1 per open issue
     *   +1 if wallet balance < 0
     *   -1 if average rating >= 4.5
     */
    public function vendorRisk(Request $request)
    {
        // Get all providers who have at least one assigned booking
        $providers = \App\Models\User::whereHas('assignedServiceBookings')
            ->with([
                'professionalWallet',
            ])
            ->get();

        $riskData = $providers->map(function ($provider) {
            $bookings = ServiceBooking::where('assigned_provider_user_id', $provider->id)->get();

            $totalAssigned       = $bookings->count();
            $completedCount      = $bookings->where('status', ServiceBooking::STATUS_COMPLETED)->count();
            $cancelledByVendor   = $bookings->where('status', ServiceBooking::STATUS_CANCELLED_BY_VENDOR)->count();
            $failedNoStart       = $bookings->where('status', ServiceBooking::STATUS_FAILED_NO_START)->count();
            $issueReported       = $bookings->whereNotNull('issue_reported_at')->count();
            $openIssues          = $bookings->where('issue_status', 'open')->count();

            // Average rating from booking_reviews
            $avgRating = \App\Models\BookingReview::where('provider_user_id', $provider->id)
                ->avg('rating');

            $walletBalance = $provider->professionalWallet
                ? (float) $provider->professionalWallet->balance
                : 0.0;

            // Penalty total
            $totalPenalty = ProfessionalWalletTransaction::where('provider_user_id', $provider->id)
                ->where('type', 'penalty_debit')
                ->sum('amount');

            // Calculate risk score
            $riskScore = 0;
            $riskScore += ($cancelledByVendor * 2);
            $riskScore += ($failedNoStart * 2);
            $riskScore += ($openIssues * 1);
            if ($walletBalance < 0) {
                $riskScore += 1;
            }
            if ($avgRating !== null && (float) $avgRating >= 4.5) {
                $riskScore -= 1;
            }

            return [
                'provider_user_id'        => $provider->id,
                'provider' => [
                    'id'    => $provider->id,
                    'name'  => $provider->name,
                    'phone' => $provider->phone,
                    'email' => $provider->email,
                ],
                'total_assigned_bookings' => $totalAssigned,
                'completed_bookings'      => $completedCount,
                'cancelled_by_vendor_count' => $cancelledByVendor,
                'failed_no_start_count'   => $failedNoStart,
                'issue_reported_count'    => $issueReported,
                'average_rating'          => $avgRating !== null ? round((float) $avgRating, 2) : null,
                'wallet_balance'          => $walletBalance,
                'total_penalty_amount'    => round((float) $totalPenalty, 2),
                'risk_score'              => $riskScore,
            ];
        });

        // Sort descending by risk score
        $sorted = $riskData->sortByDesc('risk_score')->values();

        return response()->json([
            'vendor_risk' => $sorted,
            'total_providers' => $sorted->count(),
        ], 200);
    }
}
