<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\BookingReview;
use App\Models\BookingVendorOffer;
use App\Models\PlatformService;
use App\Models\ProfessionalWallet;
use App\Models\ProfessionalWalletTransaction;
use App\Models\ProviderProfile;
use App\Models\ProviderSelectedService;
use App\Models\ProviderServiceArea;
use App\Models\Role;
use App\Models\ServiceBooking;
use App\Models\ServiceCategory;
use App\Models\ShopOrder;
use App\Models\Product;
use App\Models\User;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\ShopReturnRequest;
use App\Models\ShopRefund;
use App\Models\ProductReview;
use App\Services\VendorMatchingService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 9: Admin Control Panel Controller
 *
 * Complete admin CRUD, operational control, monitoring, and issue resolution.
 *
 * All routes protected by auth:sanctum + admin middleware.
 * No real payment gateway refunds, no real bank payouts.
 * All mutating actions are logged to admin_action_logs.
 */
class AdminControlController extends Controller
{
    protected VendorMatchingService $vendorMatchingService;
    protected WalletService $walletService;

    public function __construct(VendorMatchingService $vendorMatchingService, WalletService $walletService)
    {
        $this->vendorMatchingService = $vendorMatchingService;
        $this->walletService = $walletService;
    }

    // =========================================================================
    // HELPER: Standard pagination wrapper
    // =========================================================================

    /**
     * Apply standard sorting and pagination to a query.
     */
    private function applyPagination(Request $request, $query, string $defaultSort = 'created_at', string $defaultDir = 'desc')
    {
        $sortBy = $request->input('sort_by', $defaultSort);
        $sortDir = $request->input('sort_direction', $defaultDir);

        return $query->orderBy($sortBy, $sortDir)
            ->paginate($request->integer('per_page', 15));
    }

    // =========================================================================
    // C) OVERVIEW
    // =========================================================================

    /**
     * GET /api/admin/control/overview
     */
    public function overview(Request $request)
    {
        // Users
        $totalUsers = User::count();
        $totalCustomers = User::whereHas('roles', fn($q) => $q->where('slug', 'user'))->count();
        $totalProfessionals = User::whereHas('roles', fn($q) => $q->where('slug', 'provider'))->count();
        $pendingProfessionals = ProviderProfile::where('verification_status', 'pending')->count();
        $verifiedProfessionals = ProviderProfile::where('is_verified', true)->count();
        $suspendedProfessionals = ProviderProfile::where('is_suspended', true)->count();

        // Services
        $totalPlatformServices = PlatformService::count();
        $activePlatformServices = PlatformService::where('is_active', true)->count();

        // Bookings
        $totalBookings = ServiceBooking::count();
        $todayBookings = ServiceBooking::whereDate('created_at', today())->count();
        $activeBookings = ServiceBooking::whereIn('status', [
            ServiceBooking::STATUS_BROADCASTED,
            ServiceBooking::STATUS_VENDOR_ACCEPTED,
            ServiceBooking::STATUS_CONFIRMED,
            ServiceBooking::STATUS_IN_PROGRESS,
            ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION,
        ])->count();
        $completedBookings = ServiceBooking::where('status', ServiceBooking::STATUS_COMPLETED)->count();
        $broadcastedBookings = ServiceBooking::where('status', ServiceBooking::STATUS_BROADCASTED)->count();
        $confirmedBookings = ServiceBooking::where('status', ServiceBooking::STATUS_CONFIRMED)->count();
        $inProgressBookings = ServiceBooking::where('status', ServiceBooking::STATUS_IN_PROGRESS)->count();
        $cancelledByUser = ServiceBooking::where('status', ServiceBooking::STATUS_CANCELLED_BY_USER)->count();
        $cancelledByVendor = ServiceBooking::where('status', ServiceBooking::STATUS_CANCELLED_BY_VENDOR)->count();
        $reschedPending = ServiceBooking::where('status', ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION)->count();
        $issueReportedCount = ServiceBooking::whereNotNull('issue_reported_at')->count();
        $openIssuesCount = ServiceBooking::where('issue_status', 'open')->count();
        $failedNoStartCount = ServiceBooking::where('status', ServiceBooking::STATUS_FAILED_NO_START)->count();

        // Financials
        $totalCustomerValue = ServiceBooking::sum('customer_price');
        $totalVendorPayoutReleased = ServiceBooking::where('payout_status', ServiceBooking::PAYOUT_RELEASED)->sum('vendor_expected_payout');
        $totalPlatformAmount = ServiceBooking::sum('platform_amount');
        $totalVendorPenalties = ProfessionalWalletTransaction::where('type', 'penalty_debit')->sum('amount');
        $negativeWalletVendors = ProfessionalWallet::where('balance', '<', 0)->count();

        // Breakdowns
        $bookingStatusCounts = ServiceBooking::selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $payoutStatusCounts = ServiceBooking::whereNotNull('payout_status')->selectRaw('payout_status, count(*) as count')->groupBy('payout_status')->pluck('count', 'payout_status');
        $issueStatusCounts = ServiceBooking::whereNotNull('issue_reported_at')->selectRaw('COALESCE(issue_status, \'open\') as issue_status, count(*) as count')->groupBy('issue_status')->pluck('count', 'issue_status');

        // Latest
        $latestBookings = ServiceBooking::with('user:id,name', 'platformService:id,name')
            ->latest()->take(10)->get()->map(fn($b) => [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'customer' => $b->user?->name,
                'service' => $b->platformService?->name,
                'status' => $b->status,
                'created_at' => $b->created_at,
            ]);

        $latestIssues = ServiceBooking::with('user:id,name', 'assignedProvider:id,name')
            ->whereNotNull('issue_reported_at')
            ->latest('issue_reported_at')->take(10)->get()->map(fn($b) => [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'customer' => $b->user?->name,
                'provider' => $b->assignedProvider?->name,
                'issue_status' => $b->issue_status,
                'issue_reported_at' => $b->issue_reported_at,
            ]);

        // High risk vendors (risk_score > 0)
        $highRiskVendors = $this->calculateVendorRiskData(5);

        // ── Shop Metrics (Phase 10B) ──
        $totalShopOrders      = ShopOrder::count();
        $todayShopOrders      = ShopOrder::whereDate('created_at', today())->count();
        $shopRevenue          = round((float) ShopOrder::where('payment_status', ShopOrder::PAYMENT_PAID)->sum('total_amount'), 2);
        $pendingShopOrders    = ShopOrder::where('order_status', ShopOrder::STATUS_PENDING)->count();
        $processingShopOrders = ShopOrder::where('order_status', ShopOrder::STATUS_PROCESSING)->count();
        $shippedShopOrders    = ShopOrder::where('order_status', ShopOrder::STATUS_SHIPPED)->count();
        $deliveredShopOrders  = ShopOrder::where('order_status', ShopOrder::STATUS_DELIVERED)->count();
        $cancelledShopOrders  = ShopOrder::where('order_status', ShopOrder::STATUS_CANCELLED)->count();
        $lowStockProducts     = Product::whereColumn('stock_quantity', '<=', 'low_stock_threshold')->where('stock_quantity', '>', 0)->count();
        $outOfStockProducts   = Product::where('stock_quantity', '<=', 0)->count();

        // ── Phase 10C Metrics ──
        $activeCouponsCount    = Coupon::where('is_active', true)->count();
        $couponUsageCount      = CouponUsage::count();
        $totalCouponDiscount   = round((float) CouponUsage::sum('discount_amount'), 2);
        $returnRequestedCount  = ShopReturnRequest::where('status', ShopReturnRequest::STATUS_REQUESTED)->count();
        $returnApprovedCount   = ShopReturnRequest::where('status', ShopReturnRequest::STATUS_APPROVED)->count();
        $returnRejectedCount   = ShopReturnRequest::where('status', ShopReturnRequest::STATUS_REJECTED)->count();
        $returnedCount         = ShopReturnRequest::where('status', ShopReturnRequest::STATUS_RETURNED)->count();
        $pendingRefundsCount   = ShopRefund::where('status', ShopRefund::STATUS_PENDING)->count();
        $processedRefundsCount = ShopRefund::where('status', ShopRefund::STATUS_PROCESSED)->count();
        $totalRefundAmount     = round((float) ShopRefund::where('status', ShopRefund::STATUS_PROCESSED)->sum('amount'), 2);
        $productReviewsCount   = ProductReview::count();
        $avgProductRating      = round((float) ProductReview::where('is_visible', true)->avg('rating'), 1);

        return response()->json([
            'overview' => [
                'total_users' => $totalUsers,
                'total_customers' => $totalCustomers,
                'total_professionals' => $totalProfessionals,
                'pending_professionals' => $pendingProfessionals,
                'verified_professionals' => $verifiedProfessionals,
                'suspended_professionals' => $suspendedProfessionals,
                'total_platform_services' => $totalPlatformServices,
                'active_platform_services' => $activePlatformServices,
                'total_bookings' => $totalBookings,
                'today_bookings' => $todayBookings,
                'active_bookings' => $activeBookings,
                'completed_bookings' => $completedBookings,
                'broadcasted_bookings' => $broadcastedBookings,
                'confirmed_bookings' => $confirmedBookings,
                'in_progress_bookings' => $inProgressBookings,
                'cancelled_by_user_count' => $cancelledByUser,
                'cancelled_by_vendor_count' => $cancelledByVendor,
                'reschedule_pending_count' => $reschedPending,
                'issue_reported_count' => $issueReportedCount,
                'open_issues_count' => $openIssuesCount,
                'failed_no_start_count' => $failedNoStartCount,
                'total_customer_value' => round((float) $totalCustomerValue, 2),
                'total_vendor_payout_released' => round((float) $totalVendorPayoutReleased, 2),
                'total_platform_amount' => round((float) $totalPlatformAmount, 2),
                'total_vendor_penalties' => round((float) $totalVendorPenalties, 2),
                'negative_wallet_vendors_count' => $negativeWalletVendors,
                'pending_reschedule_reconfirmations_count' => $reschedPending,
                // Shop metrics (Phase 10B)
                'total_shop_orders' => $totalShopOrders,
                'today_shop_orders' => $todayShopOrders,
                'shop_revenue' => $shopRevenue,
                'pending_shop_orders' => $pendingShopOrders,
                'processing_shop_orders' => $processingShopOrders,
                'shipped_shop_orders' => $shippedShopOrders,
                'delivered_shop_orders' => $deliveredShopOrders,
                'cancelled_shop_orders' => $cancelledShopOrders,
                'low_stock_products_count' => $lowStockProducts,
                'out_of_stock_products_count' => $outOfStockProducts,
                // Shop metrics (Phase 10C)
                'active_coupons_count' => $activeCouponsCount,
                'coupon_usage_count' => $couponUsageCount,
                'total_coupon_discount' => $totalCouponDiscount,
                'return_requested_count' => $returnRequestedCount,
                'return_approved_count' => $returnApprovedCount,
                'return_rejected_count' => $returnRejectedCount,
                'returned_count' => $returnedCount,
                'pending_refunds_count' => $pendingRefundsCount,
                'processed_refunds_count' => $processedRefundsCount,
                'total_refund_amount' => $totalRefundAmount,
                'product_reviews_count' => $productReviewsCount,
                'average_product_rating' => $avgProductRating,
            ],
            'booking_status_counts' => $bookingStatusCounts,
            'payout_status_counts' => $payoutStatusCounts,
            'issue_status_counts' => $issueStatusCounts,
            'latest_bookings' => $latestBookings,
            'latest_issues' => $latestIssues,
            'high_risk_vendors' => $highRiskVendors,
        ], 200);
    }

    // =========================================================================
    // D) USERS MANAGEMENT
    // =========================================================================

    /**
     * GET /api/admin/control/users
     */
    public function users(Request $request)
    {
        $query = User::with('roles:id,name,slug');

        // Filters
        if ($request->filled('role')) {
            $query->whereHas('roles', fn($q) => $q->where('slug', $request->role));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(function ($user) {
            $serviceBookings = ServiceBooking::where('user_id', $user->id);
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('slug'),
                'total_bookings' => (clone $serviceBookings)->count(),
                'completed_bookings' => (clone $serviceBookings)->where('status', ServiceBooking::STATUS_COMPLETED)->count(),
                'cancelled_bookings' => (clone $serviceBookings)->whereIn('status', [
                    ServiceBooking::STATUS_CANCELLED_BY_USER,
                    ServiceBooking::STATUS_CANCELLED_BY_ADMIN,
                ])->count(),
                'issues_reported' => (clone $serviceBookings)->whereNotNull('issue_reported_at')->count(),
                'account_status' => $user->is_blocked ? 'blocked' : ($user->status ?? 'active'),
                'created_at' => $user->created_at,
            ];
        });

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/control/users/{user_id}
     */
    public function userDetail(Request $request, $userId)
    {
        $user = User::with(['roles:id,name,slug', 'userProfile', 'providerProfile', 'professionalWallet'])->find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $bookings = ServiceBooking::where('user_id', $userId)
            ->with('platformService:id,name')
            ->latest()->take(20)->get()->map(fn($b) => [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'service' => $b->platformService?->name,
                'status' => $b->status,
                'customer_price' => (float) $b->customer_price,
                'created_at' => $b->created_at,
            ]);

        $reviews = BookingReview::where('user_id', $userId)
            ->with('booking:id,booking_reference')
            ->latest()->take(20)->get()->map(fn($r) => [
                'id' => $r->id,
                'booking_reference' => $r->booking?->booking_reference,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'created_at' => $r->created_at,
            ]);

        $issues = ServiceBooking::where('user_id', $userId)
            ->whereNotNull('issue_reported_at')
            ->latest('issue_reported_at')->take(20)->get()->map(fn($b) => [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'issue_status' => $b->issue_status,
                'issue_description' => $b->issue_description,
                'issue_reported_at' => $b->issue_reported_at,
            ]);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $user->status,
                'is_blocked' => $user->is_blocked,
                'blocked_at' => $user->blocked_at,
                'blocked_reason' => $user->blocked_reason,
                'created_at' => $user->created_at,
            ],
            'roles' => $user->roles->pluck('slug'),
            'user_profile' => $user->userProfile,
            'provider_profile' => $user->providerProfile,
            'wallet' => $user->professionalWallet ? [
                'balance' => (float) $user->professionalWallet->balance,
                'currency' => $user->professionalWallet->currency,
            ] : null,
            'bookings' => $bookings,
            'reviews_submitted' => $reviews,
            'issue_history' => $issues,
        ], 200);
    }

    /**
     * POST /api/admin/control/users
     */
    public function createUser(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'password' => 'required|string|min:6',
            'roles' => 'required|array|min:1',
            'roles.*' => 'string|exists:roles,slug',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'status' => 'active',
        ]);

        foreach ($validated['roles'] as $roleSlug) {
            $user->assignRole($roleSlug);
        }

        AdminActionLog::record(
            $admin->id, 'user_created', 'user', $user->id,
            "Admin created user: {$user->name}",
            null, null,
            ['name' => $user->name, 'email' => $user->email, 'roles' => $validated['roles']]
        );

        return response()->json([
            'message' => 'User created successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $validated['roles'],
            ],
        ], 201);
    }

    /**
     * POST /api/admin/control/users/{user_id}/update
     */
    public function updateUser(Request $request, $userId)
    {
        $admin = $request->user();
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'phone' => 'sometimes|nullable|string|max:20|unique:users,phone,' . $userId,
            'roles' => 'sometimes|array|min:1',
            'roles.*' => 'string|exists:roles,slug',
        ]);

        $oldValues = $user->only(['name', 'email', 'phone']);

        if (isset($validated['name'])) $user->name = $validated['name'];
        if (isset($validated['email'])) $user->email = $validated['email'];
        if (array_key_exists('phone', $validated)) $user->phone = $validated['phone'];
        $user->save();

        if (isset($validated['roles'])) {
            $roleIds = Role::whereIn('slug', $validated['roles'])->pluck('id');
            $user->roles()->sync($roleIds);
        }

        AdminActionLog::record(
            $admin->id, 'user_updated', 'user', $user->id,
            "Admin updated user: {$user->name}",
            null,
            $oldValues,
            $user->only(['name', 'email', 'phone'])
        );

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->fresh()->roles->pluck('slug'),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/users/{user_id}/block
     */
    public function blockUser(Request $request, $userId)
    {
        $admin = $request->user();
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $oldValues = ['is_blocked' => $user->is_blocked, 'status' => $user->status];

        $user->update([
            'is_blocked' => true,
            'blocked_at' => now(),
            'blocked_reason' => $validated['reason'] ?? null,
            'status' => 'blocked',
        ]);

        AdminActionLog::record(
            $admin->id, 'user_blocked', 'user', $user->id,
            $validated['reason'] ?? 'User blocked by admin.',
            null, $oldValues,
            ['is_blocked' => true, 'status' => 'blocked']
        );

        return response()->json([
            'message' => 'User blocked successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_blocked' => true,
                'blocked_at' => $user->fresh()->blocked_at,
                'blocked_reason' => $user->fresh()->blocked_reason,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/users/{user_id}/unblock
     */
    public function unblockUser(Request $request, $userId)
    {
        $admin = $request->user();
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $oldValues = ['is_blocked' => $user->is_blocked, 'status' => $user->status];

        $user->update([
            'is_blocked' => false,
            'blocked_at' => null,
            'blocked_reason' => null,
            'status' => 'active',
        ]);

        AdminActionLog::record(
            $admin->id, 'user_unblocked', 'user', $user->id,
            'User unblocked by admin.',
            null, $oldValues,
            ['is_blocked' => false, 'status' => 'active']
        );

        return response()->json([
            'message' => 'User unblocked successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'is_blocked' => false,
            ],
        ], 200);
    }

    // =========================================================================
    // E) VENDOR MANAGEMENT
    // =========================================================================

    /**
     * GET /api/admin/control/vendors
     */
    public function vendors(Request $request)
    {
        $query = User::whereHas('roles', fn($q) => $q->where('slug', 'provider'))
            ->with(['providerProfile', 'professionalWallet']);

        // Filters
        if ($request->filled('verification_status')) {
            $query->whereHas('providerProfile', fn($q) => $q->where('verification_status', $request->verification_status));
        }
        if ($request->filled('is_verified')) {
            $query->whereHas('providerProfile', fn($q) => $q->where('is_verified', $request->boolean('is_verified')));
        }
        if ($request->filled('suspended')) {
            $query->whereHas('providerProfile', fn($q) => $q->where('is_suspended', $request->boolean('suspended')));
        }
        if ($request->filled('service_id')) {
            $query->whereHas('providerSelectedServices', fn($q) => $q->where('platform_service_id', $request->service_id));
        }
        if ($request->filled('city')) {
            $query->whereHas('providerServiceAreas', fn($q) => $q->where('city', 'like', '%' . $request->city . '%'));
        }
        if ($request->filled('state')) {
            $query->whereHas('providerServiceAreas', fn($q) => $q->where('state', 'like', '%' . $request->state . '%'));
        }
        if ($request->boolean('negative_wallet')) {
            $query->whereHas('professionalWallet', fn($q) => $q->where('balance', '<', 0));
        }

        // Search
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search);
            });
        }

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(function ($provider) {
            $profile = $provider->providerProfile;
            $bookings = ServiceBooking::where('assigned_provider_user_id', $provider->id);
            $avgRating = BookingReview::where('provider_user_id', $provider->id)->avg('rating');

            // Risk score
            $cancelledByVendor = (clone $bookings)->where('status', ServiceBooking::STATUS_CANCELLED_BY_VENDOR)->count();
            $failedNoStart = (clone $bookings)->where('status', ServiceBooking::STATUS_FAILED_NO_START)->count();
            $openIssues = (clone $bookings)->where('issue_status', 'open')->count();
            $walletBalance = $provider->professionalWallet ? (float) $provider->professionalWallet->balance : 0;

            $riskScore = ($cancelledByVendor * 2) + ($failedNoStart * 2) + $openIssues;
            if ($walletBalance < 0) $riskScore += 1;
            if ($avgRating !== null && (float) $avgRating >= 4.5) $riskScore -= 1;

            return [
                'provider_user_id' => $provider->id,
                'name' => $provider->name,
                'email' => $provider->email,
                'phone' => $provider->phone,
                'verification_status' => $profile?->verification_status,
                'is_verified' => (bool) ($profile?->is_verified),
                'is_suspended' => (bool) ($profile?->is_suspended),
                'selected_services_count' => $provider->providerSelectedServices()->count(),
                'service_areas_count' => $provider->providerServiceAreas()->count(),
                'wallet_balance' => $walletBalance,
                'completed_bookings' => (clone $bookings)->where('status', ServiceBooking::STATUS_COMPLETED)->count(),
                'cancelled_by_vendor_count' => $cancelledByVendor,
                'failed_no_start_count' => $failedNoStart,
                'issues_count' => (clone $bookings)->whereNotNull('issue_reported_at')->count(),
                'average_rating' => $avgRating !== null ? round((float) $avgRating, 2) : null,
                'risk_score' => $riskScore,
            ];
        });

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/control/vendors/{provider_user_id}
     */
    public function vendorDetail(Request $request, $providerUserId)
    {
        $provider = User::with([
            'providerProfile',
            'professionalWallet',
            'providerSelectedServices.platformService:id,name',
            'providerServiceAreas',
        ])->find($providerUserId);

        if (!$provider) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $profile = $provider->providerProfile;

        // Bookings
        $bookings = ServiceBooking::where('assigned_provider_user_id', $providerUserId)
            ->with('platformService:id,name')
            ->latest()->take(30)->get();

        $bookingStats = ServiceBooking::where('assigned_provider_user_id', $providerUserId)
            ->selectRaw("
                count(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled_by_vendor' THEN 1 ELSE 0 END) as cancelled_by_vendor,
                SUM(CASE WHEN status = 'failed_no_start' THEN 1 ELSE 0 END) as failed_no_start,
                SUM(CASE WHEN issue_reported_at IS NOT NULL THEN 1 ELSE 0 END) as issues
            ")->first();

        // Wallet transactions
        $wallet = $provider->professionalWallet;
        $transactions = $wallet
            ? ProfessionalWalletTransaction::where('wallet_id', $wallet->id)
                ->with('booking:id,booking_reference')
                ->latest()->take(30)->get()
            : collect();

        // Penalties
        $penalties = ProfessionalWalletTransaction::where('provider_user_id', $providerUserId)
            ->where('type', 'penalty_debit')
            ->with('booking:id,booking_reference')
            ->latest()->get();

        // Reviews
        $reviews = BookingReview::where('provider_user_id', $providerUserId)
            ->with(['booking:id,booking_reference', 'user:id,name'])
            ->latest()->take(20)->get();

        $avgRating = BookingReview::where('provider_user_id', $providerUserId)->avg('rating');

        // Risk summary
        $cancelledByVendor = $bookingStats->cancelled_by_vendor ?? 0;
        $failedNoStart = $bookingStats->failed_no_start ?? 0;
        $openIssues = ServiceBooking::where('assigned_provider_user_id', $providerUserId)->where('issue_status', 'open')->count();
        $walletBalance = $wallet ? (float) $wallet->balance : 0;
        $totalPenalty = ProfessionalWalletTransaction::where('provider_user_id', $providerUserId)->where('type', 'penalty_debit')->sum('amount');

        $riskScore = ($cancelledByVendor * 2) + ($failedNoStart * 2) + $openIssues;
        if ($walletBalance < 0) $riskScore += 1;
        if ($avgRating !== null && (float) $avgRating >= 4.5) $riskScore -= 1;

        return response()->json([
            'user' => [
                'id' => $provider->id,
                'name' => $provider->name,
                'email' => $provider->email,
                'phone' => $provider->phone,
                'status' => $provider->status,
                'is_blocked' => $provider->is_blocked,
                'created_at' => $provider->created_at,
            ],
            'provider_profile' => $profile,
            'selected_services' => $provider->providerSelectedServices->map(fn($s) => [
                'id' => $s->id,
                'platform_service_id' => $s->platform_service_id,
                'service_name' => $s->platformService?->name,
                'is_active' => $s->is_active,
            ]),
            'service_areas' => $provider->providerServiceAreas,
            'wallet' => $wallet ? [
                'balance' => (float) $wallet->balance,
                'currency' => $wallet->currency,
                'is_active' => $wallet->is_active,
            ] : null,
            'transactions' => $transactions->map(fn($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'direction' => $tx->direction,
                'amount' => (float) $tx->amount,
                'description' => $tx->description,
                'balance_before' => (float) $tx->balance_before,
                'balance_after' => (float) $tx->balance_after,
                'booking_reference' => $tx->booking?->booking_reference,
                'reference' => $tx->reference,
                'created_at' => $tx->created_at,
            ]),
            'booking_stats' => [
                'total' => $bookingStats->total ?? 0,
                'completed' => $bookingStats->completed ?? 0,
                'cancelled_by_vendor' => $cancelledByVendor,
                'failed_no_start' => $failedNoStart,
                'issues' => $bookingStats->issues ?? 0,
            ],
            'bookings' => $bookings->map(fn($b) => [
                'id' => $b->id,
                'booking_reference' => $b->booking_reference,
                'service' => $b->platformService?->name,
                'status' => $b->status,
                'customer_price' => (float) $b->customer_price,
                'created_at' => $b->created_at,
            ]),
            'penalties' => $penalties->map(fn($p) => [
                'id' => $p->id,
                'amount' => (float) $p->amount,
                'description' => $p->description,
                'booking_reference' => $p->booking?->booking_reference,
                'reference' => $p->reference,
                'created_at' => $p->created_at,
            ]),
            'reviews' => $reviews->map(fn($r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'customer' => $r->user?->name,
                'booking_reference' => $r->booking?->booking_reference,
                'is_visible' => $r->is_visible,
                'created_at' => $r->created_at,
            ]),
            'risk_summary' => [
                'average_rating' => $avgRating !== null ? round((float) $avgRating, 2) : null,
                'wallet_balance' => $walletBalance,
                'total_penalty_amount' => round((float) $totalPenalty, 2),
                'risk_score' => $riskScore,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/verify
     */
    public function verifyVendor(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $provider = User::with('providerProfile')->find($providerUserId);

        if (!$provider || !$provider->providerProfile) {
            return response()->json(['message' => 'Vendor profile not found.'], 404);
        }

        $profile = $provider->providerProfile;
        $oldValues = ['verification_status' => $profile->verification_status, 'is_verified' => $profile->is_verified];

        $profile->update([
            'verification_status' => 'verified',
            'is_verified' => true,
        ]);

        AdminActionLog::record(
            $admin->id, 'vendor_verified', 'provider_profile', $profile->id,
            "Vendor {$provider->name} verified by admin.",
            null, $oldValues,
            ['verification_status' => 'verified', 'is_verified' => true]
        );

        return response()->json([
            'message' => 'Vendor verified successfully.',
            'provider_profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/reject
     */
    public function rejectVendor(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $validated = $request->validate(['reason' => 'required|string|max:2000']);

        $provider = User::with('providerProfile')->find($providerUserId);

        if (!$provider || !$provider->providerProfile) {
            return response()->json(['message' => 'Vendor profile not found.'], 404);
        }

        $profile = $provider->providerProfile;
        $oldValues = ['verification_status' => $profile->verification_status, 'is_verified' => $profile->is_verified];

        $profile->update([
            'verification_status' => 'rejected',
            'is_verified' => false,
        ]);

        AdminActionLog::record(
            $admin->id, 'vendor_rejected', 'provider_profile', $profile->id,
            $validated['reason'],
            null, $oldValues,
            ['verification_status' => 'rejected', 'is_verified' => false]
        );

        return response()->json([
            'message' => 'Vendor rejected.',
            'provider_profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/suspend
     */
    public function suspendVendor(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $validated = $request->validate(['reason' => 'required|string|max:2000']);

        $provider = User::with('providerProfile')->find($providerUserId);

        if (!$provider || !$provider->providerProfile) {
            return response()->json(['message' => 'Vendor profile not found.'], 404);
        }

        $profile = $provider->providerProfile;
        $oldValues = ['is_suspended' => $profile->is_suspended];

        $profile->update([
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspension_reason' => $validated['reason'],
        ]);

        AdminActionLog::record(
            $admin->id, 'vendor_suspended', 'provider_profile', $profile->id,
            $validated['reason'],
            null, $oldValues,
            ['is_suspended' => true, 'suspension_reason' => $validated['reason']]
        );

        return response()->json([
            'message' => 'Vendor suspended.',
            'provider_profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/reactivate
     */
    public function reactivateVendor(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $provider = User::with('providerProfile')->find($providerUserId);

        if (!$provider || !$provider->providerProfile) {
            return response()->json(['message' => 'Vendor profile not found.'], 404);
        }

        $profile = $provider->providerProfile;
        $oldValues = ['is_suspended' => $profile->is_suspended, 'suspension_reason' => $profile->suspension_reason];

        $profile->update([
            'is_suspended' => false,
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        AdminActionLog::record(
            $admin->id, 'vendor_reactivated', 'provider_profile', $profile->id,
            "Vendor {$provider->name} reactivated by admin.",
            null, $oldValues,
            ['is_suspended' => false]
        );

        return response()->json([
            'message' => 'Vendor reactivated.',
            'provider_profile' => $profile->fresh(),
        ], 200);
    }

    /**
     * GET /api/admin/control/vendors/{provider_user_id}/selected-services
     */
    public function vendorSelectedServices(Request $request, $providerUserId)
    {
        $provider = User::find($providerUserId);
        if (!$provider) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $services = ProviderSelectedService::where('provider_user_id', $providerUserId)
            ->with('platformService:id,name,customer_price,is_active')
            ->get();

        return response()->json(['selected_services' => $services], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/selected-services/sync
     */
    public function syncVendorSelectedServices(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'service_ids' => 'required|array',
            'service_ids.*' => 'integer|exists:platform_services,id',
        ]);

        $provider = User::find($providerUserId);
        if (!$provider) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $oldServiceIds = ProviderSelectedService::where('provider_user_id', $providerUserId)
            ->pluck('platform_service_id')->toArray();

        // Sync: remove old, add new
        ProviderSelectedService::where('provider_user_id', $providerUserId)->delete();

        foreach ($validated['service_ids'] as $serviceId) {
            ProviderSelectedService::create([
                'provider_user_id' => $providerUserId,
                'platform_service_id' => $serviceId,
                'is_active' => true,
            ]);
        }

        AdminActionLog::record(
            $admin->id, 'vendor_services_synced', 'user', $providerUserId,
            "Admin synced selected services for vendor #{$providerUserId}.",
            null,
            ['service_ids' => $oldServiceIds],
            ['service_ids' => $validated['service_ids']]
        );

        return response()->json([
            'message' => 'Vendor selected services synced.',
            'service_ids' => $validated['service_ids'],
        ], 200);
    }

    /**
     * GET /api/admin/control/vendors/{provider_user_id}/service-areas
     */
    public function vendorServiceAreas(Request $request, $providerUserId)
    {
        $provider = User::find($providerUserId);
        if (!$provider) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $areas = ProviderServiceArea::where('provider_user_id', $providerUserId)->get();
        return response()->json(['service_areas' => $areas], 200);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/service-areas
     */
    public function createVendorServiceArea(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $provider = User::find($providerUserId);
        if (!$provider) {
            return response()->json(['message' => 'Vendor not found.'], 404);
        }

        $validated = $request->validate([
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:20',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_km' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['provider_user_id'] = $providerUserId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $area = ProviderServiceArea::create($validated);

        AdminActionLog::record(
            $admin->id, 'vendor_service_area_created', 'provider_service_area', $area->id,
            "Admin created service area for vendor #{$providerUserId}.",
            null, null,
            $validated
        );

        return response()->json([
            'message' => 'Service area created.',
            'service_area' => $area,
        ], 201);
    }

    /**
     * POST /api/admin/control/vendors/{provider_user_id}/service-areas/{area_id}/update
     */
    public function updateVendorServiceArea(Request $request, $providerUserId, $areaId)
    {
        $admin = $request->user();
        $area = ProviderServiceArea::where('provider_user_id', $providerUserId)->where('id', $areaId)->first();

        if (!$area) {
            return response()->json(['message' => 'Service area not found.'], 404);
        }

        $validated = $request->validate([
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:20',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'radius_km' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        $oldValues = $area->toArray();
        $area->update($validated);

        AdminActionLog::record(
            $admin->id, 'vendor_service_area_updated', 'provider_service_area', $area->id,
            "Admin updated service area #{$areaId} for vendor #{$providerUserId}.",
            null, $oldValues, $area->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Service area updated.',
            'service_area' => $area->fresh(),
        ], 200);
    }

    /**
     * DELETE /api/admin/control/vendors/{provider_user_id}/service-areas/{area_id}
     */
    public function deleteVendorServiceArea(Request $request, $providerUserId, $areaId)
    {
        $admin = $request->user();
        $area = ProviderServiceArea::where('provider_user_id', $providerUserId)->where('id', $areaId)->first();

        if (!$area) {
            return response()->json(['message' => 'Service area not found.'], 404);
        }

        $oldValues = $area->toArray();
        $area->delete();

        AdminActionLog::record(
            $admin->id, 'vendor_service_area_deleted', 'provider_service_area', $areaId,
            "Admin deleted service area #{$areaId} for vendor #{$providerUserId}.",
            null, $oldValues, null
        );

        return response()->json(['message' => 'Service area deleted.'], 200);
    }

    // =========================================================================
    // F) SERVICE CATEGORIES & PLATFORM SERVICES CRUD
    // =========================================================================

    /**
     * GET /api/admin/control/service-categories
     */
    public function serviceCategories(Request $request)
    {
        $categories = ServiceCategory::withCount('platformServices')->get();
        return response()->json(['data' => $categories], 200);
    }

    /**
     * POST /api/admin/control/service-categories
     */
    public function createServiceCategory(Request $request)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['is_active'] = $validated['is_active'] ?? true;

        $category = ServiceCategory::create($validated);

        AdminActionLog::record(
            $admin->id, 'service_category_created', 'service_category', $category->id,
            "Admin created service category: {$category->name}.",
            null, null, $validated
        );

        return response()->json([
            'message' => 'Service category created.',
            'service_category' => $category,
        ], 201);
    }

    /**
     * POST /api/admin/control/service-categories/{id}/update
     */
    public function updateServiceCategory(Request $request, $id)
    {
        $admin = $request->user();
        $category = ServiceCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Service category not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $oldValues = $category->toArray();

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        AdminActionLog::record(
            $admin->id, 'service_category_updated', 'service_category', $category->id,
            "Admin updated service category: {$category->name}.",
            null, $oldValues, $category->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Service category updated.',
            'service_category' => $category->fresh(),
        ], 200);
    }

    /**
     * POST /api/admin/control/service-categories/{id}/toggle-status
     */
    public function toggleServiceCategoryStatus(Request $request, $id)
    {
        $admin = $request->user();
        $category = ServiceCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Service category not found.'], 404);
        }

        $oldStatus = $category->is_active;
        $category->is_active = !$category->is_active;
        $category->save();

        AdminActionLog::record(
            $admin->id, 'service_category_toggled', 'service_category', $category->id,
            "Category status toggled to " . ($category->is_active ? 'active' : 'inactive') . ".",
            null, ['is_active' => $oldStatus], ['is_active' => $category->is_active]
        );

        return response()->json([
            'message' => "Service category is now " . ($category->is_active ? "active" : "inactive") . ".",
            'service_category' => $category,
        ], 200);
    }

    /**
     * DELETE /api/admin/control/service-categories/{id}
     */
    public function deleteServiceCategory(Request $request, $id)
    {
        $admin = $request->user();
        $category = ServiceCategory::find($id);
        if (!$category) {
            return response()->json(['message' => 'Service category not found.'], 404);
        }

        // Check if any platform services use this category
        if ($category->platformServices()->exists()) {
            return response()->json(['message' => 'Cannot delete category with existing platform services. Deactivate instead.'], 422);
        }

        $oldValues = $category->toArray();
        $category->delete();

        AdminActionLog::record(
            $admin->id, 'service_category_deleted', 'service_category', $id,
            "Admin deleted service category: {$oldValues['name']}.",
            null, $oldValues, null
        );

        return response()->json(['message' => 'Service category deleted.'], 200);
    }

    /**
     * GET /api/admin/control/platform-services
     */
    public function platformServices(Request $request)
    {
        $query = PlatformService::with('category:id,name,slug');

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)->orWhere('slug', 'like', $search);
            });
        }
        if ($request->filled('category_id')) {
            $query->where('service_category_id', $request->category_id);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginated = $this->applyPagination($request, $query);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/platform-services
     */
    public function createPlatformService(Request $request)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'service_category_id' => 'nullable|exists:service_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'customer_price' => 'required|numeric|min:0',
            'vendor_payout_percentage' => 'required|numeric|min:0|max:100',
            'platform_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'requires_start_otp' => 'nullable|boolean',
        ]);

        if (!isset($validated['platform_percentage'])) {
            $validated['platform_percentage'] = 100 - $validated['vendor_payout_percentage'];
        }

        $validated['slug'] = $this->generateUniqueServiceSlug($validated['name']);
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['requires_start_otp'] = $validated['requires_start_otp'] ?? true;

        $service = PlatformService::create($validated);
        $service->load('category:id,name,slug');

        AdminActionLog::record(
            $admin->id, 'platform_service_created', 'platform_service', $service->id,
            "Admin created platform service: {$service->name}.",
            null, null, $validated
        );

        return response()->json([
            'message' => 'Platform service created.',
            'platform_service' => $service,
        ], 201);
    }

    /**
     * GET /api/admin/control/platform-services/{id}
     */
    public function platformServiceDetail(Request $request, $id)
    {
        $service = PlatformService::with('category:id,name,slug')->find($id);
        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        $bookingsCount = ServiceBooking::where('platform_service_id', $id)->count();
        $providersCount = ProviderSelectedService::where('platform_service_id', $id)->count();

        return response()->json([
            'platform_service' => $service,
            'stats' => [
                'total_bookings' => $bookingsCount,
                'total_providers' => $providersCount,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/platform-services/{id}/update
     */
    public function updatePlatformService(Request $request, $id)
    {
        $admin = $request->user();
        $service = PlatformService::find($id);
        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        $validated = $request->validate([
            'service_category_id' => 'nullable|exists:service_categories,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'customer_price' => 'sometimes|numeric|min:0',
            'vendor_payout_percentage' => 'sometimes|numeric|min:0|max:100',
            'platform_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'requires_start_otp' => 'nullable|boolean',
        ]);

        if (isset($validated['vendor_payout_percentage']) && !isset($validated['platform_percentage'])) {
            $validated['platform_percentage'] = 100 - $validated['vendor_payout_percentage'];
        }

        if (isset($validated['name']) && $validated['name'] !== $service->name) {
            $validated['slug'] = $this->generateUniqueServiceSlug($validated['name'], $service->id);
        }

        $oldValues = $service->toArray();
        $service->update($validated);

        AdminActionLog::record(
            $admin->id, 'platform_service_updated', 'platform_service', $service->id,
            "Admin updated platform service: {$service->name}.",
            null, $oldValues, $service->fresh()->toArray()
        );

        return response()->json([
            'message' => 'Platform service updated.',
            'platform_service' => $service->fresh()->load('category:id,name,slug'),
        ], 200);
    }

    /**
     * POST /api/admin/control/platform-services/{id}/toggle-status
     */
    public function togglePlatformServiceStatus(Request $request, $id)
    {
        $admin = $request->user();
        $service = PlatformService::find($id);
        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        $oldStatus = $service->is_active;
        $service->is_active = !$service->is_active;
        $service->save();

        AdminActionLog::record(
            $admin->id, 'platform_service_toggled', 'platform_service', $service->id,
            "Service status toggled to " . ($service->is_active ? 'active' : 'inactive') . ".",
            null, ['is_active' => $oldStatus], ['is_active' => $service->is_active]
        );

        return response()->json([
            'message' => "Platform service is now " . ($service->is_active ? "active" : "inactive") . ".",
            'platform_service' => $service->load('category:id,name,slug'),
        ], 200);
    }

    /**
     * DELETE /api/admin/control/platform-services/{id}
     */
    public function deletePlatformService(Request $request, $id)
    {
        $admin = $request->user();
        $service = PlatformService::find($id);
        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        // If service has bookings, deactivate instead of deleting
        if (ServiceBooking::where('platform_service_id', $id)->exists()) {
            $service->update(['is_active' => false]);

            AdminActionLog::record(
                $admin->id, 'platform_service_deactivated', 'platform_service', $id,
                "Service has existing bookings — deactivated instead of deleted.",
                null, ['is_active' => true], ['is_active' => false]
            );

            return response()->json([
                'message' => 'Service has existing bookings. Deactivated instead of deleted.',
                'platform_service' => $service->fresh(),
            ], 200);
        }

        $oldValues = $service->toArray();
        $service->delete();

        AdminActionLog::record(
            $admin->id, 'platform_service_deleted', 'platform_service', $id,
            "Admin deleted platform service: {$oldValues['name']}.",
            null, $oldValues, null
        );

        return response()->json(['message' => 'Platform service deleted.'], 200);
    }

    /**
     * Generate a unique slug for platform services.
     */
    private function generateUniqueServiceSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $original = $slug;
        $counter = 1;

        while (true) {
            $query = PlatformService::where('slug', $slug);
            if ($ignoreId) $query->where('id', '!=', $ignoreId);
            if (!$query->exists()) break;
            $slug = "{$original}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    // =========================================================================
    // G) BOOKINGS CONTROL
    // =========================================================================

    /**
     * GET /api/admin/control/bookings
     */
    public function bookings(Request $request)
    {
        $query = ServiceBooking::with([
            'platformService:id,name,service_category_id',
            'platformService.category:id,name',
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
        ]);

        // Filters
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('payment_status')) $query->where('payment_status', $request->payment_status);
        if ($request->filled('payout_status')) $query->where('payout_status', $request->payout_status);
        if ($request->filled('service_id')) $query->where('platform_service_id', $request->service_id);
        if ($request->filled('provider_user_id')) $query->where('assigned_provider_user_id', $request->provider_user_id);
        if ($request->filled('user_id')) $query->where('user_id', $request->user_id);
        if ($request->filled('date_from')) $query->whereDate('preferred_date', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('preferred_date', '<=', $request->date_to);
        if ($request->filled('issue_status')) $query->where('issue_status', $request->issue_status);
        if ($request->boolean('rescheduled_only')) $query->where('reschedule_count', '>', 0);
        if ($request->boolean('cancelled_only')) {
            $query->whereIn('status', [
                ServiceBooking::STATUS_CANCELLED_BY_USER,
                ServiceBooking::STATUS_CANCELLED_BY_VENDOR,
                ServiceBooking::STATUS_CANCELLED_BY_ADMIN,
                ServiceBooking::STATUS_CANCELLED_WITHIN_WINDOW,
            ]);
        }

        // Search
        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search)
                  ->orWhere('customer_email', 'like', $search)
                  ->orWhereHas('assignedProvider', fn($pq) => $pq->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                  ->orWhereHas('platformService', fn($sq) => $sq->where('name', 'like', $search));
            });
        }

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(fn($booking) => [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'customer' => [
                'id' => $booking->user?->id,
                'name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
            ],
            'provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
            ] : null,
            'service' => [
                'id' => $booking->platformService?->id,
                'name' => $booking->platformService?->name,
                'category' => $booking->platformService?->category?->name,
            ],
            'preferred_date' => $booking->preferred_date?->format('Y-m-d'),
            'preferred_time' => $booking->preferred_time,
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
            'payout_status' => $booking->payout_status,
            'customer_price' => (float) $booking->customer_price,
            'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
            'platform_amount' => (float) $booking->platform_amount,
            'reschedule_count' => $booking->reschedule_count,
            'cancellation_fee' => (float) $booking->cancellation_fee,
            'refund_amount' => (float) $booking->refund_amount,
            'issue_status' => $booking->issue_status,
            'created_at' => $booking->created_at,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/control/bookings/{booking_id}
     */
    public function bookingDetail(Request $request, $bookingId)
    {
        $booking = ServiceBooking::with([
            'user:id,name,phone,email',
            'platformService.category:id,name',
            'assignedProvider:id,name,phone,email',
            'vendorOffers.provider:id,name,phone,email',
            'walletTransactions',
            'review',
        ])->find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        // Timeline
        $timeline = [];
        $tsMap = [
            'created_at' => 'Booking created',
            'vendor_accepted_at' => 'Vendor accepted',
            'action_window_ends_at' => 'Action window ends',
            'confirmed_at' => 'Booking confirmed',
            'start_otp_generated_at' => 'Start OTP generated',
            'start_otp_verified_at' => 'Start OTP verified',
            'job_started_at' => 'Job started',
            'job_completed_at' => 'Job completed',
            'payout_released_at' => 'Payout released',
            'cancelled_at' => 'Booking cancelled',
            'last_rescheduled_at' => 'Last rescheduled',
            'issue_reported_at' => 'Issue reported',
            'issue_resolved_at' => 'Issue resolved',
            'no_start_marked_at' => 'No-start marked',
            'reschedule_reconfirmation_deadline_at' => 'Reschedule reconfirmation deadline',
            'reschedule_reconfirmed_at' => 'Reschedule reconfirmed',
        ];

        foreach ($tsMap as $field => $label) {
            if (!empty($booking->$field)) {
                $timeline[] = ['event' => $label, 'field' => $field, 'timestamp' => $booking->$field];
            }
        }
        usort($timeline, fn($a, $b) => strtotime($a['timestamp']) <=> strtotime($b['timestamp']));

        // Admin action logs for this booking
        $adminLogs = AdminActionLog::where('entity_type', 'service_booking')
            ->where('entity_id', $bookingId)
            ->with('admin:id,name')
            ->latest()->get()->map(fn($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'admin' => $log->admin?->name,
                'description' => $log->description,
                'created_at' => $log->created_at,
            ]);

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'payout_status' => $booking->payout_status,
                'preferred_date' => $booking->preferred_date?->format('Y-m-d'),
                'preferred_time' => $booking->preferred_time,
                'notes' => $booking->notes,
                'created_at' => $booking->created_at,
                'customer' => [
                    'id' => $booking->user?->id,
                    'name' => $booking->customer_name,
                    'phone' => $booking->customer_phone,
                    'email' => $booking->customer_email,
                ],
                'assigned_provider' => $booking->assignedProvider ? [
                    'id' => $booking->assignedProvider->id,
                    'name' => $booking->assignedProvider->name,
                    'phone' => $booking->assignedProvider->phone,
                    'email' => $booking->assignedProvider->email,
                ] : null,
                'service' => [
                    'id' => $booking->platformService?->id,
                    'name' => $booking->platformService?->name,
                    'category' => $booking->platformService?->category?->name,
                    'customer_price' => (float) $booking->customer_price,
                    'vendor_payout_percentage' => (float) $booking->vendor_payout_percentage,
                    'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
                    'platform_amount' => (float) $booking->platform_amount,
                    'payout_release_reference' => $booking->payout_release_reference,
                ],
                'timeline' => $timeline,
                'vendor_offers' => $booking->vendorOffers->map(fn($offer) => [
                    'id' => $offer->id,
                    'provider' => $offer->provider ? [
                        'id' => $offer->provider->id,
                        'name' => $offer->provider->name,
                        'phone' => $offer->provider->phone,
                    ] : null,
                    'status' => $offer->status,
                    'sent_at' => $offer->sent_at,
                    'accepted_at' => $offer->accepted_at,
                    'rejected_at' => $offer->rejected_at,
                    'expired_at' => $offer->expired_at,
                ]),
                'wallet_transactions' => $booking->walletTransactions->map(fn($tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'direction' => $tx->direction,
                    'amount' => (float) $tx->amount,
                    'description' => $tx->description,
                    'balance_before' => (float) $tx->balance_before,
                    'balance_after' => (float) $tx->balance_after,
                    'reference' => $tx->reference,
                    'created_at' => $tx->created_at,
                ]),
                'review' => $booking->review ? [
                    'id' => $booking->review->id,
                    'rating' => $booking->review->rating,
                    'comment' => $booking->review->comment,
                    'is_visible' => $booking->review->is_visible,
                    'created_at' => $booking->review->created_at,
                ] : null,
                'cancellation' => [
                    'cancelled_by' => $booking->cancelled_by,
                    'cancelled_at' => $booking->cancelled_at,
                    'cancellation_reason' => $booking->cancellation_reason,
                    'cancellation_fee' => (float) $booking->cancellation_fee,
                    'refund_amount' => (float) $booking->refund_amount,
                    'refund_status' => $booking->refund_status,
                    'refund_note' => $booking->refund_note,
                ],
                'reschedule' => [
                    'reschedule_count' => $booking->reschedule_count,
                    'last_rescheduled_at' => $booking->last_rescheduled_at,
                    'original_preferred_date' => $booking->original_preferred_date?->format('Y-m-d'),
                    'original_preferred_time' => $booking->original_preferred_time,
                    'reschedule_reason' => $booking->reschedule_reason,
                    'previous_assigned_provider_user_id' => $booking->previous_assigned_provider_user_id,
                    'reschedule_reconfirmation_deadline_at' => $booking->reschedule_reconfirmation_deadline_at,
                    'reschedule_reconfirmed_at' => $booking->reschedule_reconfirmed_at,
                ],
                'issue' => [
                    'issue_reported_at' => $booking->issue_reported_at,
                    'issue_status' => $booking->issue_status,
                    'issue_description' => $booking->issue_description,
                    'issue_resolution_note' => $booking->issue_resolution_note,
                    'issue_resolved_at' => $booking->issue_resolved_at,
                    'issue_resolved_by' => $booking->issue_resolved_by,
                ],
                'admin_action_logs' => $adminLogs,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/rebroadcast
     */
    public function rebroadcastBooking(Request $request, $bookingId)
    {
        $admin = $request->user();
        $booking = ServiceBooking::find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        DB::transaction(function () use ($booking) {
            // Expire old offers
            BookingVendorOffer::where('booking_id', $booking->id)
                ->whereIn('status', [BookingVendorOffer::STATUS_SENT, BookingVendorOffer::STATUS_ACCEPTED])
                ->update(['status' => BookingVendorOffer::STATUS_EXPIRED, 'expired_at' => now()]);

            // Clear assignment
            $booking->update([
                'assigned_provider_user_id' => null,
                'vendor_accepted_at' => null,
                'action_window_ends_at' => null,
                'confirmed_at' => null,
                'status' => ServiceBooking::STATUS_BROADCASTED,
            ]);
        });

        // Rebroadcast
        $result = $this->vendorMatchingService->broadcastToMatchingVendors($booking->fresh());

        AdminActionLog::record(
            $admin->id, 'booking_rebroadcast', 'service_booking', $booking->id,
            "Admin rebroadcasted booking.",
            ['vendors_found' => $result['vendors_found'], 'offers_created' => $result['offers_created']]
        );

        return response()->json([
            'message' => 'Booking rebroadcasted.',
            'vendors_found' => $result['vendors_found'],
            'offers_created' => $result['offers_created'],
            'status' => $booking->fresh()->status,
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/assign-provider
     */
    public function assignProvider(Request $request, $bookingId)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'provider_user_id' => 'required|integer|exists:users,id',
            'note' => 'nullable|string|max:2000',
        ]);

        $booking = ServiceBooking::find($bookingId);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $provider = User::with('providerProfile')->find($validated['provider_user_id']);
        if (!$provider || !$provider->hasRole('provider')) {
            return response()->json(['message' => 'Provider not found or does not have provider role.'], 422);
        }

        if ($provider->providerProfile && $provider->providerProfile->is_suspended) {
            return response()->json(['message' => 'Provider is suspended.'], 422);
        }

        $oldValues = [
            'assigned_provider_user_id' => $booking->assigned_provider_user_id,
            'status' => $booking->status,
        ];

        $booking->update([
            'assigned_provider_user_id' => $provider->id,
            'vendor_accepted_at' => now(),
            'status' => ServiceBooking::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        // Generate OTP if service requires it
        if ($booking->otp_required || ($booking->platformService && $booking->platformService->requires_start_otp)) {
            $booking->generateStartOtp();
        }

        AdminActionLog::record(
            $admin->id, 'booking_manual_assignment', 'service_booking', $booking->id,
            $validated['note'] ?? "Admin manually assigned provider #{$provider->id} to booking.",
            null, $oldValues,
            ['assigned_provider_user_id' => $provider->id, 'status' => ServiceBooking::STATUS_CONFIRMED]
        );

        return response()->json([
            'message' => 'Provider assigned and booking confirmed.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'assigned_provider_user_id' => $provider->id,
                'status' => $booking->fresh()->status,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/force-confirm
     */
    public function forceConfirm(Request $request, $bookingId)
    {
        $admin = $request->user();
        $booking = ServiceBooking::find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $oldStatus = $booking->status;

        $booking->update([
            'status' => ServiceBooking::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);

        // Generate OTP if required
        if ($booking->otp_required || ($booking->platformService && $booking->platformService->requires_start_otp)) {
            $booking->generateStartOtp();
        }

        AdminActionLog::record(
            $admin->id, 'booking_force_confirmed', 'service_booking', $booking->id,
            "Admin force-confirmed booking from status: {$oldStatus}.",
            null, ['status' => $oldStatus],
            ['status' => ServiceBooking::STATUS_CONFIRMED]
        );

        return response()->json([
            'message' => 'Booking force-confirmed.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->fresh()->status,
                'confirmed_at' => $booking->fresh()->confirmed_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/cancel-by-admin
     */
    public function cancelByAdmin(Request $request, $bookingId)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $booking = ServiceBooking::find($bookingId);
        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $oldValues = ['status' => $booking->status, 'cancelled_by' => $booking->cancelled_by];

        $booking->update([
            'status' => ServiceBooking::STATUS_CANCELLED_BY_ADMIN,
            'cancelled_by' => 'admin',
            'cancellation_reason' => $validated['reason'],
            'cancelled_at' => now(),
        ]);

        AdminActionLog::record(
            $admin->id, 'booking_cancelled_by_admin', 'service_booking', $booking->id,
            $validated['reason'],
            null, $oldValues,
            ['status' => ServiceBooking::STATUS_CANCELLED_BY_ADMIN, 'cancelled_by' => 'admin']
        );

        return response()->json([
            'message' => 'Booking cancelled by admin.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->fresh()->status,
                'cancelled_at' => $booking->fresh()->cancelled_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/mark-no-start
     */
    public function markNoStart(Request $request, $bookingId)
    {
        $admin = $request->user();
        $booking = ServiceBooking::find($bookingId);

        if (!$booking) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $oldStatus = $booking->status;

        $booking->update([
            'status' => ServiceBooking::STATUS_FAILED_NO_START,
            'no_start_marked_at' => now(),
        ]);

        AdminActionLog::record(
            $admin->id, 'booking_marked_no_start', 'service_booking', $booking->id,
            "Admin marked booking as failed_no_start.",
            null, ['status' => $oldStatus],
            ['status' => ServiceBooking::STATUS_FAILED_NO_START]
        );

        return response()->json([
            'message' => 'Booking marked as failed_no_start.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'status' => $booking->fresh()->status,
                'no_start_marked_at' => $booking->fresh()->no_start_marked_at,
            ],
        ], 200);
    }

    // =========================================================================
    // H) JOB OFFERS
    // =========================================================================

    /**
     * GET /api/admin/control/job-offers
     */
    public function jobOffers(Request $request)
    {
        $query = BookingVendorOffer::with([
            'booking:id,booking_reference,platform_service_id',
            'booking.platformService:id,name',
            'provider:id,name,phone,email',
        ]);

        if ($request->filled('booking_id')) $query->where('booking_id', $request->booking_id);
        if ($request->filled('provider_user_id')) $query->where('provider_user_id', $request->provider_user_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->date_to);

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(fn($offer) => [
            'id' => $offer->id,
            'booking_reference' => $offer->booking?->booking_reference,
            'provider' => $offer->provider ? [
                'id' => $offer->provider->id,
                'name' => $offer->provider->name,
                'phone' => $offer->provider->phone,
            ] : null,
            'service' => $offer->booking?->platformService?->name,
            'status' => $offer->status,
            'sent_at' => $offer->sent_at,
            'accepted_at' => $offer->accepted_at,
            'rejected_at' => $offer->rejected_at,
            'expired_at' => $offer->expired_at,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/job-offers/{offer_id}/expire
     */
    public function expireOffer(Request $request, $offerId)
    {
        $admin = $request->user();
        $offer = BookingVendorOffer::find($offerId);

        if (!$offer) {
            return response()->json(['message' => 'Job offer not found.'], 404);
        }

        if ($offer->status !== BookingVendorOffer::STATUS_SENT) {
            return response()->json(['message' => 'Only sent offers can be expired.'], 422);
        }

        $offer->update([
            'status' => BookingVendorOffer::STATUS_EXPIRED,
            'expired_at' => now(),
        ]);

        AdminActionLog::record(
            $admin->id, 'job_offer_expired', 'booking_vendor_offer', $offer->id,
            "Admin expired job offer #{$offer->id}.",
            null, ['status' => BookingVendorOffer::STATUS_SENT],
            ['status' => BookingVendorOffer::STATUS_EXPIRED]
        );

        return response()->json([
            'message' => 'Job offer expired.',
            'offer' => ['id' => $offer->id, 'status' => 'expired'],
        ], 200);
    }

    // =========================================================================
    // I) ISSUES / DISPUTES
    // =========================================================================

    /**
     * GET /api/admin/control/issues
     */
    public function issues(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
        ])->whereNotNull('issue_reported_at');

        if ($request->filled('issue_status')) $query->where('issue_status', $request->issue_status);
        if ($request->filled('provider_user_id')) $query->where('assigned_provider_user_id', $request->provider_user_id);
        if ($request->filled('user_id')) $query->where('user_id', $request->user_id);
        if ($request->filled('date_from')) $query->whereDate('issue_reported_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('issue_reported_at', '<=', $request->date_to);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('issue_description', 'like', $search)
                  ->orWhereHas('assignedProvider', fn($pq) => $pq->where('name', 'like', $search));
            });
        }

        $paginated = $this->applyPagination($request, $query, 'issue_reported_at');

        $paginated->getCollection()->transform(fn($booking) => [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'customer' => [
                'id' => $booking->user?->id,
                'name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
                'email' => $booking->customer_email,
            ],
            'provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
            ] : null,
            'service' => $booking->platformService?->name,
            'issue_status' => $booking->issue_status,
            'issue_description' => $booking->issue_description,
            'issue_reported_at' => $booking->issue_reported_at,
            'payout_status' => $booking->payout_status,
            'booking_status' => $booking->status,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/resolve-issue
     */
    public function resolveIssue(Request $request, $bookingId)
    {
        $admin = $request->user();
        $validated = $request->validate(['resolution_note' => 'required|string|max:2000']);

        $booking = ServiceBooking::find($bookingId);
        if (!$booking) return response()->json(['message' => 'Booking not found.'], 404);
        if (!$booking->issue_reported_at) return response()->json(['message' => 'No issue reported for this booking.'], 422);
        if (!in_array($booking->issue_status, ['open', null])) return response()->json(['message' => 'Issue is already ' . $booking->issue_status . '.'], 422);

        $booking->update([
            'issue_status' => 'resolved',
            'issue_resolution_note' => $validated['resolution_note'],
            'issue_resolved_at' => now(),
            'issue_resolved_by' => $admin->id,
        ]);

        AdminActionLog::record(
            $admin->id, 'issue_resolved', 'service_booking', $booking->id,
            $validated['resolution_note'],
            ['booking_reference' => $booking->booking_reference],
            ['issue_status' => 'open'],
            ['issue_status' => 'resolved']
        );

        return response()->json([
            'message' => 'Issue resolved successfully.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'issue_status' => 'resolved',
                'issue_resolved_at' => $booking->fresh()->issue_resolved_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/reject-issue
     */
    public function rejectIssue(Request $request, $bookingId)
    {
        $admin = $request->user();
        $validated = $request->validate(['resolution_note' => 'required|string|max:2000']);

        $booking = ServiceBooking::find($bookingId);
        if (!$booking) return response()->json(['message' => 'Booking not found.'], 404);
        if (!$booking->issue_reported_at) return response()->json(['message' => 'No issue reported for this booking.'], 422);
        if (!in_array($booking->issue_status, ['open', null])) return response()->json(['message' => 'Issue is already ' . $booking->issue_status . '.'], 422);

        $booking->update([
            'issue_status' => 'rejected',
            'issue_resolution_note' => $validated['resolution_note'],
            'issue_resolved_at' => now(),
            'issue_resolved_by' => $admin->id,
        ]);

        AdminActionLog::record(
            $admin->id, 'issue_rejected', 'service_booking', $booking->id,
            $validated['resolution_note'],
            ['booking_reference' => $booking->booking_reference],
            ['issue_status' => 'open'],
            ['issue_status' => 'rejected']
        );

        return response()->json([
            'message' => 'Issue rejected. Payout reversal is not performed in this phase.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'issue_status' => 'rejected',
                'issue_resolved_at' => $booking->fresh()->issue_resolved_at,
            ],
        ], 200);
    }

    // =========================================================================
    // J) CANCELLATIONS / REFUND LEDGER
    // =========================================================================

    /**
     * GET /api/admin/control/cancellations
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
            ServiceBooking::STATUS_CANCELLED_BY_ADMIN,
            ServiceBooking::STATUS_CANCELLED_WITHIN_WINDOW,
        ]);

        if ($request->filled('cancelled_by')) $query->where('cancelled_by', $request->cancelled_by);
        if ($request->filled('date_from')) $query->whereDate('cancelled_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('cancelled_at', '<=', $request->date_to);
        if ($request->filled('service_id')) $query->where('platform_service_id', $request->service_id);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search);
            });
        }

        $paginated = $this->applyPagination($request, $query, 'cancelled_at');

        $paginated->getCollection()->transform(fn($booking) => [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'cancelled_by' => $booking->cancelled_by,
            'cancelled_at' => $booking->cancelled_at,
            'cancellation_reason' => $booking->cancellation_reason,
            'cancellation_fee' => (float) $booking->cancellation_fee,
            'refund_amount' => (float) $booking->refund_amount,
            'refund_status' => $booking->refund_status,
            'customer_price' => (float) $booking->customer_price,
            'customer' => [
                'id' => $booking->user?->id,
                'name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
            ],
            'provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
            ] : null,
            'service' => $booking->platformService?->name,
            'status' => $booking->status,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/update-refund-ledger-status
     */
    public function updateRefundLedgerStatus(Request $request, $bookingId)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'refund_status' => 'required|in:pending,processed,rejected',
            'note' => 'nullable|string|max:2000',
        ]);

        $booking = ServiceBooking::find($bookingId);
        if (!$booking) return response()->json(['message' => 'Booking not found.'], 404);

        $oldValues = ['refund_status' => $booking->refund_status, 'refund_note' => $booking->refund_note];

        $booking->update([
            'refund_status' => $validated['refund_status'],
            'refund_note' => $validated['note'] ?? $booking->refund_note,
        ]);

        AdminActionLog::record(
            $admin->id, 'refund_ledger_updated', 'service_booking', $booking->id,
            $validated['note'] ?? "Refund status updated to: {$validated['refund_status']}.",
            null, $oldValues,
            ['refund_status' => $validated['refund_status']]
        );

        return response()->json([
            'message' => 'Refund ledger status updated. No real refund processed.',
            'booking' => [
                'id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'refund_status' => $booking->fresh()->refund_status,
                'refund_note' => $booking->fresh()->refund_note,
            ],
        ], 200);
    }

    // =========================================================================
    // K) RESCHEDULES
    // =========================================================================

    /**
     * GET /api/admin/control/reschedules
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

        $paginated = $this->applyPagination($request, $query, 'last_rescheduled_at');

        $paginated->getCollection()->transform(fn($booking) => [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'customer' => [
                'id' => $booking->user?->id,
                'name' => $booking->customer_name,
                'phone' => $booking->customer_phone,
            ],
            'provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
            ] : null,
            'original_preferred_date' => $booking->original_preferred_date?->format('Y-m-d'),
            'original_preferred_time' => $booking->original_preferred_time,
            'current_preferred_date' => $booking->preferred_date?->format('Y-m-d'),
            'current_preferred_time' => $booking->preferred_time,
            'reschedule_count' => $booking->reschedule_count,
            'reschedule_reason' => $booking->reschedule_reason,
            'status' => $booking->status,
            'last_rescheduled_at' => $booking->last_rescheduled_at,
            'reschedule_reconfirmation_deadline_at' => $booking->reschedule_reconfirmation_deadline_at,
            'reschedule_reconfirmed_at' => $booking->reschedule_reconfirmed_at,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/reschedules/expire-pending
     */
    public function expirePendingReschedules(Request $request)
    {
        $admin = $request->user();

        $expired = ServiceBooking::where('status', ServiceBooking::STATUS_RESCHEDULE_PENDING_PROVIDER_CONFIRMATION)
            ->where('reschedule_reconfirmation_deadline_at', '<=', now())
            ->get();

        $count = 0;

        foreach ($expired as $booking) {
            DB::transaction(function () use ($booking) {
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->whereIn('status', [BookingVendorOffer::STATUS_SENT, BookingVendorOffer::STATUS_ACCEPTED])
                    ->update(['status' => BookingVendorOffer::STATUS_EXPIRED]);

                $booking->update([
                    'status' => ServiceBooking::STATUS_BROADCASTED,
                    'assigned_provider_user_id' => null,
                    'vendor_accepted_at' => null,
                    'action_window_ends_at' => null,
                    'confirmed_at' => null,
                ]);
            });

            try {
                $this->vendorMatchingService->broadcastToMatchingVendors($booking->fresh());
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Admin: rebroadcast failed for booking #{$booking->id}: " . $e->getMessage());
            }

            $count++;
        }

        AdminActionLog::record(
            $admin->id, 'reschedule_expired', 'service_booking', 0,
            "Admin expired {$count} pending reschedule reconfirmations.",
            ['count' => $count]
        );

        return response()->json([
            'message' => "Expired and rebroadcasted {$count} pending reschedule reconfirmation(s).",
            'count' => $count,
        ], 200);
    }

    /**
     * POST /api/admin/control/bookings/{booking_id}/force-rebroadcast-reschedule
     */
    public function forceRebroadcastReschedule(Request $request, $bookingId)
    {
        $admin = $request->user();
        $booking = ServiceBooking::find($bookingId);

        if (!$booking) return response()->json(['message' => 'Booking not found.'], 404);

        $oldValues = [
            'assigned_provider_user_id' => $booking->assigned_provider_user_id,
            'status' => $booking->status,
        ];

        DB::transaction(function () use ($booking) {
            BookingVendorOffer::where('booking_id', $booking->id)
                ->whereIn('status', [BookingVendorOffer::STATUS_SENT, BookingVendorOffer::STATUS_ACCEPTED])
                ->update(['status' => BookingVendorOffer::STATUS_EXPIRED, 'expired_at' => now()]);

            $booking->update([
                'assigned_provider_user_id' => null,
                'vendor_accepted_at' => null,
                'action_window_ends_at' => null,
                'confirmed_at' => null,
                'status' => ServiceBooking::STATUS_BROADCASTED,
            ]);
        });

        $result = $this->vendorMatchingService->broadcastToMatchingVendors($booking->fresh());

        AdminActionLog::record(
            $admin->id, 'reschedule_force_rebroadcast', 'service_booking', $booking->id,
            "Admin force-rebroadcasted rescheduled booking.",
            ['vendors_found' => $result['vendors_found'], 'offers_created' => $result['offers_created']],
            $oldValues,
            ['status' => ServiceBooking::STATUS_BROADCASTED, 'assigned_provider_user_id' => null]
        );

        return response()->json([
            'message' => 'Rescheduled booking rebroadcasted.',
            'vendors_found' => $result['vendors_found'],
            'offers_created' => $result['offers_created'],
        ], 200);
    }

    // =========================================================================
    // L) WALLETS / PENALTIES
    // =========================================================================

    /**
     * GET /api/admin/control/wallets
     */
    public function wallets(Request $request)
    {
        $query = ProfessionalWallet::with('providerUser:id,name,phone,email');

        if ($request->boolean('negative_only')) $query->where('balance', '<', 0);
        if ($request->filled('provider_user_id')) $query->where('provider_user_id', $request->provider_user_id);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->whereHas('providerUser', fn($q) => $q
                ->where('name', 'like', $search)
                ->orWhere('phone', 'like', $search)
                ->orWhere('email', 'like', $search));
        }

        $paginated = $this->applyPagination($request, $query, 'balance', 'asc');

        $paginated->getCollection()->transform(function ($wallet) {
            $txStats = ProfessionalWalletTransaction::where('wallet_id', $wallet->id)
                ->selectRaw("
                    SUM(CASE WHEN type = 'recharge' THEN amount ELSE 0 END) as total_recharges,
                    SUM(CASE WHEN type = 'penalty_debit' THEN amount ELSE 0 END) as total_penalties,
                    SUM(CASE WHEN type = 'payout_credit' THEN amount ELSE 0 END) as total_payouts,
                    MAX(created_at) as last_transaction_at
                ")->first();

            return [
                'provider_user_id' => $wallet->provider_user_id,
                'provider' => [
                    'id' => $wallet->providerUser?->id,
                    'name' => $wallet->providerUser?->name,
                    'phone' => $wallet->providerUser?->phone,
                    'email' => $wallet->providerUser?->email,
                ],
                'balance' => (float) $wallet->balance,
                'currency' => $wallet->currency,
                'total_recharges' => round((float) ($txStats->total_recharges ?? 0), 2),
                'total_penalties' => round((float) ($txStats->total_penalties ?? 0), 2),
                'total_payouts' => round((float) ($txStats->total_payouts ?? 0), 2),
                'last_transaction_at' => $txStats->last_transaction_at,
            ];
        });

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * GET /api/admin/control/wallets/{provider_user_id}/transactions
     */
    public function walletTransactions(Request $request, $providerUserId)
    {
        $wallet = ProfessionalWallet::where('provider_user_id', $providerUserId)
            ->with('providerUser:id,name,phone,email')
            ->first();

        if (!$wallet) return response()->json(['message' => 'Wallet not found for this provider.'], 404);

        $query = ProfessionalWalletTransaction::where('wallet_id', $wallet->id)
            ->with('booking:id,booking_reference');

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(fn($tx) => [
            'id' => $tx->id,
            'date' => $tx->created_at,
            'booking_reference' => $tx->booking?->booking_reference,
            'type' => $tx->type,
            'direction' => $tx->direction,
            'amount' => (float) $tx->amount,
            'description' => $tx->description,
            'balance_before' => (float) $tx->balance_before,
            'balance_after' => (float) $tx->balance_after,
            'reference' => $tx->reference,
        ]);

        return response()->json([
            'provider' => [
                'id' => $wallet->providerUser?->id,
                'name' => $wallet->providerUser?->name,
                'phone' => $wallet->providerUser?->phone,
            ],
            'wallet' => [
                'balance' => (float) $wallet->balance,
                'currency' => $wallet->currency,
            ],
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/wallets/{provider_user_id}/adjust
     */
    public function adjustWallet(Request $request, $providerUserId)
    {
        $admin = $request->user();
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'direction' => 'required|in:credit,debit',
            'reason' => 'required|string|max:2000',
        ]);

        $provider = User::find($providerUserId);
        if (!$provider) return response()->json(['message' => 'Provider not found.'], 404);

        $walletBefore = ProfessionalWallet::where('provider_user_id', $providerUserId)->first();
        $oldBalance = $walletBefore ? (float) $walletBefore->balance : 0;

        $wallet = $this->walletService->adjustBalance(
            $providerUserId,
            $validated['amount'],
            $validated['direction'],
            $validated['reason']
        );

        AdminActionLog::record(
            $admin->id, 'wallet_adjusted', 'professional_wallet', $wallet->id,
            $validated['reason'],
            null,
            ['balance' => $oldBalance],
            ['balance' => (float) $wallet->balance, 'direction' => $validated['direction'], 'amount' => $validated['amount']]
        );

        return response()->json([
            'message' => 'Wallet adjusted successfully.',
            'wallet' => [
                'provider_user_id' => $providerUserId,
                'balance_before' => $oldBalance,
                'adjustment' => ($validated['direction'] === 'credit' ? '+' : '-') . $validated['amount'],
                'balance_after' => (float) $wallet->balance,
                'reason' => $validated['reason'],
            ],
        ], 200);
    }

    /**
     * GET /api/admin/control/penalties
     */
    public function penalties(Request $request)
    {
        $query = ProfessionalWalletTransaction::where('type', 'penalty_debit')
            ->with([
                'providerUser:id,name,phone,email',
                'booking:id,booking_reference',
            ]);

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(fn($tx) => [
            'id' => $tx->id,
            'provider' => $tx->providerUser ? [
                'id' => $tx->providerUser->id,
                'name' => $tx->providerUser->name,
                'phone' => $tx->providerUser->phone,
            ] : null,
            'booking_reference' => $tx->booking?->booking_reference,
            'penalty_amount' => (float) $tx->amount,
            'reason' => $tx->description,
            'reference' => $tx->reference,
            'created_at' => $tx->created_at,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    // =========================================================================
    // M) PAYOUTS
    // =========================================================================

    /**
     * GET /api/admin/control/payouts
     */
    public function payouts(Request $request)
    {
        $query = ServiceBooking::with([
            'user:id,name,phone,email',
            'assignedProvider:id,name,phone,email',
            'platformService:id,name',
            'walletTransactions' => fn($q) => $q->where('type', 'payout_credit'),
        ])->whereNotNull('payout_status');

        if ($request->filled('payout_status')) $query->where('payout_status', $request->payout_status);
        if ($request->filled('provider_user_id')) $query->where('assigned_provider_user_id', $request->provider_user_id);
        if ($request->filled('date_from')) $query->whereDate('payout_released_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('payout_released_at', '<=', $request->date_to);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhereHas('assignedProvider', fn($pq) => $pq->where('name', 'like', $search));
            });
        }

        $paginated = $this->applyPagination($request, $query, 'payout_released_at');

        $paginated->getCollection()->transform(fn($booking) => [
            'id' => $booking->id,
            'booking_reference' => $booking->booking_reference,
            'provider' => $booking->assignedProvider ? [
                'id' => $booking->assignedProvider->id,
                'name' => $booking->assignedProvider->name,
                'phone' => $booking->assignedProvider->phone,
            ] : null,
            'customer' => [
                'id' => $booking->user?->id,
                'name' => $booking->customer_name,
            ],
            'service' => $booking->platformService?->name,
            'vendor_expected_payout' => (float) $booking->vendor_expected_payout,
            'payout_status' => $booking->payout_status,
            'payout_released_at' => $booking->payout_released_at,
            'payout_release_reference' => $booking->payout_release_reference,
            'wallet_transaction_reference' => $booking->walletTransactions->first()?->reference,
            'booking_status' => $booking->status,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    // =========================================================================
    // N) REVIEWS
    // =========================================================================

    /**
     * GET /api/admin/control/reviews
     */
    public function reviews(Request $request)
    {
        $query = BookingReview::with([
            'booking:id,booking_reference',
            'user:id,name',
            'providerUser:id,name',
        ]);

        if ($request->filled('rating')) $query->where('rating', $request->rating);
        if ($request->filled('provider_user_id')) $query->where('provider_user_id', $request->provider_user_id);
        if ($request->filled('user_id')) $query->where('user_id', $request->user_id);
        if ($request->filled('visible')) $query->where('is_visible', $request->boolean('visible'));

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', $search)
                  ->orWhereHas('user', fn($uq) => $uq->where('name', 'like', $search))
                  ->orWhereHas('providerUser', fn($pq) => $pq->where('name', 'like', $search));
            });
        }

        $paginated = $this->applyPagination($request, $query);

        $paginated->getCollection()->transform(fn($review) => [
            'id' => $review->id,
            'booking_reference' => $review->booking?->booking_reference,
            'customer' => $review->user?->name,
            'provider' => $review->providerUser?->name,
            'service' => null, // Could be loaded from booking->platformService if needed
            'rating' => $review->rating,
            'comment' => $review->comment,
            'is_visible' => $review->is_visible,
            'created_at' => $review->created_at,
        ]);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/control/reviews/{review_id}/hide
     */
    public function hideReview(Request $request, $reviewId)
    {
        $admin = $request->user();
        $review = BookingReview::find($reviewId);

        if (!$review) return response()->json(['message' => 'Review not found.'], 404);

        $review->update(['is_visible' => false]);

        AdminActionLog::record(
            $admin->id, 'review_hidden', 'booking_review', $review->id,
            "Admin hid review #{$review->id}.",
            null, ['is_visible' => true], ['is_visible' => false]
        );

        return response()->json([
            'message' => 'Review hidden.',
            'review' => ['id' => $review->id, 'is_visible' => false],
        ], 200);
    }

    /**
     * POST /api/admin/control/reviews/{review_id}/unhide
     */
    public function unhideReview(Request $request, $reviewId)
    {
        $admin = $request->user();
        $review = BookingReview::find($reviewId);

        if (!$review) return response()->json(['message' => 'Review not found.'], 404);

        $review->update(['is_visible' => true]);

        AdminActionLog::record(
            $admin->id, 'review_unhidden', 'booking_review', $review->id,
            "Admin unhid review #{$review->id}.",
            null, ['is_visible' => false], ['is_visible' => true]
        );

        return response()->json([
            'message' => 'Review unhidden.',
            'review' => ['id' => $review->id, 'is_visible' => true],
        ], 200);
    }

    // =========================================================================
    // O) VENDOR RISK
    // =========================================================================

    /**
     * GET /api/admin/control/vendor-risk
     */
    public function vendorRisk(Request $request)
    {
        $riskData = $this->calculateVendorRiskData();

        return response()->json([
            'data' => $riskData,
            'total_providers' => $riskData->count(),
        ], 200);
    }

    /**
     * Calculate vendor risk data. If $limit is provided, only return top N.
     */
    private function calculateVendorRiskData(?int $limit = null)
    {
        $providers = User::whereHas('assignedServiceBookings')
            ->with('professionalWallet')
            ->get();

        $riskData = $providers->map(function ($provider) {
            $bookings = ServiceBooking::where('assigned_provider_user_id', $provider->id)->get();

            $totalAssigned = $bookings->count();
            $completed = $bookings->where('status', ServiceBooking::STATUS_COMPLETED)->count();
            $cancelledByVendor = $bookings->where('status', ServiceBooking::STATUS_CANCELLED_BY_VENDOR)->count();
            $failedNoStart = $bookings->where('status', ServiceBooking::STATUS_FAILED_NO_START)->count();
            $issueReported = $bookings->whereNotNull('issue_reported_at')->count();
            $openIssues = $bookings->where('issue_status', 'open')->count();

            $avgRating = BookingReview::where('provider_user_id', $provider->id)->avg('rating');
            $walletBalance = $provider->professionalWallet ? (float) $provider->professionalWallet->balance : 0;
            $totalPenalty = ProfessionalWalletTransaction::where('provider_user_id', $provider->id)
                ->where('type', 'penalty_debit')->sum('amount');

            $riskScore = ($cancelledByVendor * 2) + ($failedNoStart * 2) + $openIssues;
            if ($walletBalance < 0) $riskScore += 1;
            if ($avgRating !== null && (float) $avgRating >= 4.5) $riskScore -= 1;

            return [
                'provider_user_id' => $provider->id,
                'provider' => [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'phone' => $provider->phone,
                    'email' => $provider->email,
                ],
                'total_assigned_bookings' => $totalAssigned,
                'completed_bookings' => $completed,
                'cancelled_by_vendor_count' => $cancelledByVendor,
                'failed_no_start_count' => $failedNoStart,
                'issue_reported_count' => $issueReported,
                'average_rating' => $avgRating !== null ? round((float) $avgRating, 2) : null,
                'wallet_balance' => $walletBalance,
                'total_penalty_amount' => round((float) $totalPenalty, 2),
                'risk_score' => $riskScore,
            ];
        });

        $sorted = $riskData->sortByDesc('risk_score')->values();

        if ($limit) {
            $sorted = $sorted->take($limit);
        }

        return $sorted;
    }

    // =========================================================================
    // ADMIN ACTION LOGS
    // =========================================================================

    /**
     * GET /api/admin/control/action-logs
     */
    public function actionLogs(Request $request)
    {
        $query = AdminActionLog::with('admin:id,name,email');

        if ($request->filled('action')) $query->where('action', $request->action);
        if ($request->filled('entity_type')) $query->where('entity_type', $request->entity_type);
        if ($request->filled('entity_id')) $query->where('entity_id', $request->entity_id);
        if ($request->filled('admin_user_id')) $query->where('admin_user_id', $request->admin_user_id);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
        if ($request->filled('date_to')) $query->whereDate('created_at', '<=', $request->date_to);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('entity_type', 'like', $search);
            });
        }

        $paginated = $this->applyPagination($request, $query);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
        ], 200);
    }
}
