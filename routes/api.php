<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserDashboardController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\ProfessionalServiceController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\ProfessionalAvailabilityController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ProfessionalBookingController;
use App\Http\Controllers\Api\AdminPlatformServiceController;
use App\Http\Controllers\Api\AdminSupportController;
use App\Http\Controllers\Api\PublicPlatformServiceController;
use App\Http\Controllers\Api\ProfessionalSelectedServiceController;
use App\Http\Controllers\Api\ProfessionalServiceAreaController;
use App\Http\Controllers\Api\ServiceBookingController;
use App\Http\Controllers\Api\VendorJobOfferController;
use App\Http\Controllers\Api\ProviderDashboardController;
use App\Http\Controllers\Api\SystemBookingController;
use App\Http\Controllers\Api\ProfessionalWalletController;

use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\AdminControlController;

// Phase 10A: E-commerce Product Catalog
use App\Http\Controllers\Api\AdminShopCategoryController;
use App\Http\Controllers\Api\AdminShopProductController;
use App\Http\Controllers\Api\PublicShopController;

// Phase 10B: Cart, Checkout, Shop Orders
use App\Http\Controllers\Api\ShopCartController;
use App\Http\Controllers\Api\ShopCheckoutController;
use App\Http\Controllers\Api\ShopMyOrderController;
use App\Http\Controllers\Api\AdminShopOrderController;

// Phase 10C: Coupons, Returns, Refunds, Reviews
use App\Http\Controllers\Api\AdminShopCouponController;
use App\Http\Controllers\Api\AdminShopReturnController;
use App\Http\Controllers\Api\AdminShopRefundController;
use App\Http\Controllers\Api\AdminShopProductReviewController;

// Phase 11: In-App Notifications
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AdminNotificationController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password Reset (public)
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// ============================================================
// OLD Public Service Routes (DEPRECATED under new flow)
// Old provider-created services are deprecated.
// Frontend will later hide these. Kept for backward compatibility.
// ============================================================
Route::get('/services', [ProfessionalServiceController::class, 'publicIndex']);
Route::get('/services/{id}', [ProfessionalServiceController::class, 'publicShow']);
Route::get('/services/{id}/available-slots', [ProfessionalAvailabilityController::class, 'publicSlots']);

// ============================================================
// NEW Public Platform Services (Phase 1)
// Super Admin-created services visible to customers
// ============================================================
Route::get('/platform-services', [PublicPlatformServiceController::class, 'index']);
Route::get('/platform-services/{id}', [PublicPlatformServiceController::class, 'show']);

// ============================================================
// BOOKING SUMMARY (Phase 2) — Public, no auth required
// Show service info + pricing + policies before booking
// ============================================================
Route::get('/platform-services/{id}/booking-summary', [ServiceBookingController::class, 'bookingSummary']);

// ============================================================
// PHASE 10A: PUBLIC SHOP APIs (no auth required)
// Browse product categories and published products
// ============================================================
Route::get('/shop/categories', [PublicShopController::class, 'categories']);
Route::get('/shop/products', [PublicShopController::class, 'products']);
Route::get('/shop/products/{slug}', [PublicShopController::class, 'productDetail']);

// Phase 10C: Public product reviews (no auth required)
Route::get('/shop/products/{product_id}/reviews', [ShopMyOrderController::class, 'productReviews']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // ============================================================
    // OLD Bookings (DEPRECATED under new flow)
    // Kept for backward compatibility with old provider-service bookings.
    // ============================================================
    Route::post('/bookings', [BookingController::class, 'store']);

    // User Dashboard - Profile
    Route::get('/profile', [UserDashboardController::class, 'getProfile']);
    Route::post('/profile/update', [UserDashboardController::class, 'updateProfile']);

    // User Dashboard - Donations
    Route::get('/my/donations', [UserDashboardController::class, 'getDonations']);
    Route::get('/my/donations/summary', [UserDashboardController::class, 'getDonationSummary']);

    // ============================================================
    // OLD User Dashboard - Bookings (DEPRECATED under new flow)
    // Old bookings from provider-created services.
    // ============================================================
    Route::get('/my/bookings/legacy', [UserDashboardController::class, 'getBookings']);
    Route::get('/my/bookings/summary', [UserDashboardController::class, 'getBookingSummary']);

    // ============================================================
    // NEW User Booking APIs (Phase 2/3)
    // Book service → mock payment → broadcast to vendors
    // ============================================================
    Route::post('/book-service', [ServiceBookingController::class, 'store']);
    Route::get('/my/bookings', [ServiceBookingController::class, 'myBookings']);

    // ============================================================
    // CUSTOMER - Start OTP (Phase 6)
    // Customer views OTP for confirmed booking
    // ============================================================
    Route::get('/bookings/{booking_id}/start-otp', [ServiceBookingController::class, 'getStartOtp']);

    // ============================================================
    // CUSTOMER - Submit Review (Phase 7)
    // Customer optionally submits review for completed booking
    // Review does NOT affect payout release
    // ============================================================
    Route::post('/bookings/{booking_id}/submit-review', [ServiceBookingController::class, 'submitReview']);

    // ============================================================
    // CUSTOMER - Cancellation, Reschedule, Issue (Phase 8)
    // ============================================================
    Route::get('/bookings/{booking_id}/cancellation-preview', [ServiceBookingController::class, 'cancellationPreview']);
    Route::post('/bookings/{booking_id}/cancel', [ServiceBookingController::class, 'cancelBooking']);
    Route::post('/bookings/{booking_id}/reschedule', [ServiceBookingController::class, 'rescheduleBooking']);
    Route::post('/bookings/{booking_id}/report-issue', [ServiceBookingController::class, 'reportIssue']);
    
    // System Endpoint
    Route::post('/system/bookings/expire-reschedule-reconfirmations', [ServiceBookingController::class, 'expireRescheduleReconfirmations']);

    // ============================================================
    // OLD Professional - Bookings Received (DEPRECATED under new flow)
    // ============================================================
    Route::get('/professional/bookings/legacy', [BookingController::class, 'professionalBookings']);
    Route::get('/professional/bookings/summary', [ProfessionalBookingController::class, 'summary']);

    // Professional Registration & Profile
    Route::post('/register/professional', [ProfessionalController::class, 'registerProfessional']);
    Route::get('/professional/profile', [ProfessionalController::class, 'getProfile']);
    Route::post('/professional/profile/update', [ProfessionalController::class, 'updateProfile']);

    // ============================================================
    // OLD Professional - My Services (DEPRECATED under new flow)
    // Providers should no longer create custom services or set prices.
    // Kept for backward compatibility. Frontend will later hide these.
    // ============================================================
    Route::get('/professional/services', [ProfessionalServiceController::class, 'index']);
    Route::post('/professional/services', [ProfessionalServiceController::class, 'store']);
    Route::get('/professional/services/{id}', [ProfessionalServiceController::class, 'show']);
    Route::post('/professional/services/{id}/update', [ProfessionalServiceController::class, 'update']);
    Route::post('/professional/services/{id}/toggle-status', [ProfessionalServiceController::class, 'toggleStatus']);

    // Service Categories
    Route::get('/service-categories', [ServiceCategoryController::class, 'index']);

    // ============================================================
    // OLD Professional - Availability Slots (DEPRECATED under new flow)
    // Providers no longer create availability slots directly.
    // Kept for backward compatibility. Frontend will later hide these.
    // ============================================================
    Route::get('/professional/availability-slots', [ProfessionalAvailabilityController::class, 'index']);
    Route::post('/professional/availability-slots', [ProfessionalAvailabilityController::class, 'store']);
    Route::post('/professional/availability-slots/bulk', [ProfessionalAvailabilityController::class, 'bulkStore']);
    Route::get('/professional/availability-slots/{id}', [ProfessionalAvailabilityController::class, 'show']);
    Route::post('/professional/availability-slots/{id}/update', [ProfessionalAvailabilityController::class, 'update']);
    Route::post('/professional/availability-slots/{id}/toggle-status', [ProfessionalAvailabilityController::class, 'toggleStatus']);
    Route::delete('/professional/availability-slots/{id}', [ProfessionalAvailabilityController::class, 'destroy']);

    // ============================================================
    // ADMIN - Platform Services Management (Phase 1)
    // Super Admin creates/manages service pricing & payout percentages
    // ============================================================
    Route::prefix('admin')->group(function () {
        Route::get('/platform-services', [AdminPlatformServiceController::class, 'index']);
        Route::post('/platform-services', [AdminPlatformServiceController::class, 'store']);
        Route::get('/platform-services/{id}', [AdminPlatformServiceController::class, 'show']);
        Route::post('/platform-services/{id}/update', [AdminPlatformServiceController::class, 'update']);
        Route::post('/platform-services/{id}/toggle-status', [AdminPlatformServiceController::class, 'toggleStatus']);
    });

    // ============================================================
    // PROFESSIONAL - Selected Services & Service Areas (Phase 1)
    // Vendors select which platform services they provide + set areas
    // ============================================================
    Route::get('/professional/available-platform-services', [ProfessionalSelectedServiceController::class, 'availablePlatformServices']);
    Route::get('/professional/selected-services', [ProfessionalSelectedServiceController::class, 'selectedServices']);
    Route::post('/professional/selected-services/sync', [ProfessionalSelectedServiceController::class, 'sync']);

    Route::get('/professional/service-areas', [ProfessionalServiceAreaController::class, 'index']);
    Route::post('/professional/service-areas', [ProfessionalServiceAreaController::class, 'store']);
    Route::get('/professional/service-areas/{id}', [ProfessionalServiceAreaController::class, 'show']);
    Route::post('/professional/service-areas/{id}/update', [ProfessionalServiceAreaController::class, 'update']);
    Route::delete('/professional/service-areas/{id}', [ProfessionalServiceAreaController::class, 'destroy']);

    // ============================================================
    // PROFESSIONAL - Job Offers (Phase 2/3)
    // Vendors view, accept, or reject broadcasted job offers
    // ============================================================
    Route::get('/professional/job-offers', [VendorJobOfferController::class, 'index']);
    Route::post('/professional/job-offers/{id}/accept', [VendorJobOfferController::class, 'accept']);
    Route::post('/professional/job-offers/{id}/reject', [VendorJobOfferController::class, 'reject']);

    // ============================================================
    // PROFESSIONAL - Assigned Bookings Dashboard (Phase 2/3/4)
    // Provider sees bookings they have accepted
    // ============================================================
    Route::get('/professional/bookings', [ProviderDashboardController::class, 'assignedBookings']);
    Route::post('/professional/bookings/{id}/cancel-within-window', [ProviderDashboardController::class, 'cancelWithinWindow']);
    Route::post('/professional/bookings/{id}/cancel-after-confirmation', [ProviderDashboardController::class, 'cancelAfterConfirmation']);

    // ============================================================
    // PROFESSIONAL - Start Job & Complete Job (Phase 6)
    // Vendor starts job (with OTP if required) and marks completion
    // ============================================================
    Route::post('/professional/bookings/{id}/start-job', [ProviderDashboardController::class, 'startJob']);
    Route::post('/professional/bookings/{id}/complete-job', [ProviderDashboardController::class, 'completeJob']);
    Route::post('/professional/bookings/{id}/accept-rescheduled-time', [ProviderDashboardController::class, 'acceptRescheduledTime']);
    Route::post('/professional/bookings/{id}/reject-rescheduled-time', [ProviderDashboardController::class, 'rejectRescheduledTime']);

    // ============================================================
    // PROFESSIONAL - Wallet & Transactions (Phase 5)
    // ============================================================
    Route::get('/professional/wallet', [ProfessionalWalletController::class, 'show']);
    Route::get('/professional/wallet/transactions', [ProfessionalWalletController::class, 'transactions']);
    Route::post('/professional/wallet/recharge-mock', [ProfessionalWalletController::class, 'rechargeMock']);

    // ============================================================
    // SYSTEM / ADMIN - Auto Confirm Expired Windows (Phase 4)
    // ============================================================
    Route::post('/system/bookings/auto-confirm-expired-windows', [SystemBookingController::class, 'autoConfirmExpiredWindows']);

    // ============================================================
    // SYSTEM / ADMIN - Mark No-Start Failures (Phase 6)
    // Detect confirmed bookings where vendor didn't start within 1 hour
    // ============================================================
    Route::post('/system/bookings/mark-no-start-failures', [SystemBookingController::class, 'markNoStartFailures']);

    // ============================================================
    // PHASE 9: ADMIN SUPPORT DASHBOARD APIs (Existing — preserved)
    // All routes require auth:sanctum + admin role (EnsureAdminRole middleware)
    // No real payment refunds, bank payouts, or SMS/email notifications.
    // ============================================================
    Route::middleware('admin')->prefix('admin/support')->group(function () {

        // B) Dashboard Overview
        Route::get('/overview', [AdminSupportController::class, 'overview']);

        // C) All Bookings (filterable, paginated)
        Route::get('/bookings', [AdminSupportController::class, 'bookings']);

        // D) Booking Detail
        Route::get('/bookings/{booking_id}', [AdminSupportController::class, 'bookingDetail']);

        // E) Issue Management
        Route::get('/issues', [AdminSupportController::class, 'issues']);
        Route::post('/bookings/{booking_id}/resolve-issue', [AdminSupportController::class, 'resolveIssue']);
        Route::post('/bookings/{booking_id}/reject-issue', [AdminSupportController::class, 'rejectIssue']);

        // F) Cancellation / Refund Ledger (mock — no real refunds)
        Route::get('/cancellations', [AdminSupportController::class, 'cancellations']);

        // G) Reschedule Monitoring
        Route::get('/reschedules', [AdminSupportController::class, 'reschedules']);
        Route::post('/reschedules/expire-pending', [AdminSupportController::class, 'expirePendingReschedules']);

        // H) Wallet + Penalty Monitoring
        Route::get('/wallets', [AdminSupportController::class, 'wallets']);
        Route::get('/wallets/{provider_user_id}/transactions', [AdminSupportController::class, 'walletTransactions']);

        // I) Payout Ledger (mock — no real bank payouts)
        Route::get('/payouts', [AdminSupportController::class, 'payouts']);

        // J) Vendor Risk Assessment
        Route::get('/vendor-risk', [AdminSupportController::class, 'vendorRisk']);
    });

    // ============================================================
    // PHASE 9: ADMIN CONTROL PANEL APIs (New — full CRUD + operational control)
    // All routes require auth:sanctum + admin role (EnsureAdminRole middleware)
    // No real payment gateway refunds, no real bank payout settlement.
    // All admin mutations are logged to admin_action_logs.
    // ============================================================
    Route::middleware('admin')->prefix('admin/control')->group(function () {

        // C) Overview
        Route::get('/overview', [AdminControlController::class, 'overview']);

        // D) Users Management
        Route::get('/users', [AdminControlController::class, 'users']);
        Route::post('/users', [AdminControlController::class, 'createUser']);
        Route::get('/users/{user_id}', [AdminControlController::class, 'userDetail']);
        Route::post('/users/{user_id}/update', [AdminControlController::class, 'updateUser']);
        Route::post('/users/{user_id}/block', [AdminControlController::class, 'blockUser']);
        Route::post('/users/{user_id}/unblock', [AdminControlController::class, 'unblockUser']);

        // E) Vendor Management
        Route::get('/vendors', [AdminControlController::class, 'vendors']);
        Route::get('/vendors/{provider_user_id}', [AdminControlController::class, 'vendorDetail']);
        Route::post('/vendors/{provider_user_id}/verify', [AdminControlController::class, 'verifyVendor']);
        Route::post('/vendors/{provider_user_id}/reject', [AdminControlController::class, 'rejectVendor']);
        Route::post('/vendors/{provider_user_id}/suspend', [AdminControlController::class, 'suspendVendor']);
        Route::post('/vendors/{provider_user_id}/reactivate', [AdminControlController::class, 'reactivateVendor']);
        Route::get('/vendors/{provider_user_id}/selected-services', [AdminControlController::class, 'vendorSelectedServices']);
        Route::post('/vendors/{provider_user_id}/selected-services/sync', [AdminControlController::class, 'syncVendorSelectedServices']);
        Route::get('/vendors/{provider_user_id}/service-areas', [AdminControlController::class, 'vendorServiceAreas']);
        Route::post('/vendors/{provider_user_id}/service-areas', [AdminControlController::class, 'createVendorServiceArea']);
        Route::post('/vendors/{provider_user_id}/service-areas/{area_id}/update', [AdminControlController::class, 'updateVendorServiceArea']);
        Route::delete('/vendors/{provider_user_id}/service-areas/{area_id}', [AdminControlController::class, 'deleteVendorServiceArea']);

        // F) Service Categories CRUD
        Route::get('/service-categories', [AdminControlController::class, 'serviceCategories']);
        Route::post('/service-categories', [AdminControlController::class, 'createServiceCategory']);
        Route::post('/service-categories/{id}/update', [AdminControlController::class, 'updateServiceCategory']);
        Route::post('/service-categories/{id}/toggle-status', [AdminControlController::class, 'toggleServiceCategoryStatus']);
        Route::delete('/service-categories/{id}', [AdminControlController::class, 'deleteServiceCategory']);

        // F) Platform Services CRUD
        Route::get('/platform-services', [AdminControlController::class, 'platformServices']);
        Route::post('/platform-services', [AdminControlController::class, 'createPlatformService']);
        Route::get('/platform-services/{id}', [AdminControlController::class, 'platformServiceDetail']);
        Route::post('/platform-services/{id}/update', [AdminControlController::class, 'updatePlatformService']);
        Route::post('/platform-services/{id}/toggle-status', [AdminControlController::class, 'togglePlatformServiceStatus']);
        Route::delete('/platform-services/{id}', [AdminControlController::class, 'deletePlatformService']);

        // G) Bookings Control
        Route::get('/bookings', [AdminControlController::class, 'bookings']);
        Route::get('/bookings/{booking_id}', [AdminControlController::class, 'bookingDetail']);
        Route::post('/bookings/{booking_id}/rebroadcast', [AdminControlController::class, 'rebroadcastBooking']);
        Route::post('/bookings/{booking_id}/assign-provider', [AdminControlController::class, 'assignProvider']);
        Route::post('/bookings/{booking_id}/force-confirm', [AdminControlController::class, 'forceConfirm']);
        Route::post('/bookings/{booking_id}/cancel-by-admin', [AdminControlController::class, 'cancelByAdmin']);
        Route::post('/bookings/{booking_id}/mark-no-start', [AdminControlController::class, 'markNoStart']);

        // H) Job Offers
        Route::get('/job-offers', [AdminControlController::class, 'jobOffers']);
        Route::post('/job-offers/{offer_id}/expire', [AdminControlController::class, 'expireOffer']);

        // I) Issues / Disputes
        Route::get('/issues', [AdminControlController::class, 'issues']);
        Route::post('/bookings/{booking_id}/resolve-issue', [AdminControlController::class, 'resolveIssue']);
        Route::post('/bookings/{booking_id}/reject-issue', [AdminControlController::class, 'rejectIssue']);

        // J) Cancellations / Refund Ledger
        Route::get('/cancellations', [AdminControlController::class, 'cancellations']);
        Route::post('/bookings/{booking_id}/update-refund-ledger-status', [AdminControlController::class, 'updateRefundLedgerStatus']);

        // K) Reschedules
        Route::get('/reschedules', [AdminControlController::class, 'reschedules']);
        Route::post('/reschedules/expire-pending', [AdminControlController::class, 'expirePendingReschedules']);
        Route::post('/bookings/{booking_id}/force-rebroadcast-reschedule', [AdminControlController::class, 'forceRebroadcastReschedule']);

        // L) Wallets / Penalties
        Route::get('/wallets', [AdminControlController::class, 'wallets']);
        Route::get('/wallets/{provider_user_id}/transactions', [AdminControlController::class, 'walletTransactions']);
        Route::post('/wallets/{provider_user_id}/adjust', [AdminControlController::class, 'adjustWallet']);
        Route::get('/penalties', [AdminControlController::class, 'penalties']);

        // M) Payouts
        Route::get('/payouts', [AdminControlController::class, 'payouts']);

        // N) Reviews
        Route::get('/reviews', [AdminControlController::class, 'reviews']);
        Route::post('/reviews/{review_id}/hide', [AdminControlController::class, 'hideReview']);
        Route::post('/reviews/{review_id}/unhide', [AdminControlController::class, 'unhideReview']);

        // O) Vendor Risk
        Route::get('/vendor-risk', [AdminControlController::class, 'vendorRisk']);

        // Admin Action Logs
        Route::get('/action-logs', [AdminControlController::class, 'actionLogs']);

        // ── Phase 11: Admin Notifications ──
        Route::get('/notifications', [AdminNotificationController::class, 'index']);
        Route::get('/notifications/overview', [AdminNotificationController::class, 'overview']);
    });

    // ============================================================
    // PHASE 10A: ADMIN SHOP — E-commerce Product Catalog Management
    // All routes require auth:sanctum + admin role (EnsureAdminRole middleware)
    // Separate from service bookings. No cart, checkout, or orders in this phase.
    // ============================================================
    Route::middleware('admin')->prefix('admin/shop')->group(function () {

        // A) Product Categories CRUD
        Route::get('/categories', [AdminShopCategoryController::class, 'index']);
        Route::post('/categories', [AdminShopCategoryController::class, 'store']);
        Route::get('/categories/{id}', [AdminShopCategoryController::class, 'show']);
        Route::post('/categories/{id}/update', [AdminShopCategoryController::class, 'update']);
        Route::post('/categories/{id}/toggle-status', [AdminShopCategoryController::class, 'toggleStatus']);
        Route::delete('/categories/{id}', [AdminShopCategoryController::class, 'destroy']);

        // B) Products CRUD
        Route::get('/products', [AdminShopProductController::class, 'index']);
        Route::post('/products', [AdminShopProductController::class, 'store']);
        Route::get('/products/{id}', [AdminShopProductController::class, 'show']);
        Route::post('/products/{id}/update', [AdminShopProductController::class, 'update']);
        Route::post('/products/{id}/toggle-status', [AdminShopProductController::class, 'toggleStatus']);
        Route::post('/products/{id}/toggle-featured', [AdminShopProductController::class, 'toggleFeatured']);
        Route::delete('/products/{id}', [AdminShopProductController::class, 'destroy']);

        // C) Product Images
        Route::post('/products/{id}/images', [AdminShopProductController::class, 'uploadImage']);
        Route::delete('/products/{id}/images/{image_id}', [AdminShopProductController::class, 'deleteImage']);
        Route::post('/products/{id}/images/{image_id}/set-primary', [AdminShopProductController::class, 'setPrimaryImage']);

        // D) Inventory / Stock Adjustment
        Route::post('/products/{id}/stock-adjust', [AdminShopProductController::class, 'stockAdjust']);

        // ── Phase 10B: Admin Shop Orders ──
        Route::get('/orders', [AdminShopOrderController::class, 'index']);
        Route::get('/orders/{order_id}', [AdminShopOrderController::class, 'show']);
        Route::post('/orders/{order_id}/update-status', [AdminShopOrderController::class, 'updateStatus']);
        Route::post('/orders/{order_id}/add-note', [AdminShopOrderController::class, 'addNote']);

        // ── Phase 10C: Admin Coupons ──
        Route::get('/coupons', [AdminShopCouponController::class, 'index']);
        Route::post('/coupons', [AdminShopCouponController::class, 'store']);
        Route::get('/coupons/{id}', [AdminShopCouponController::class, 'show']);
        Route::post('/coupons/{id}/update', [AdminShopCouponController::class, 'update']);
        Route::post('/coupons/{id}/toggle-status', [AdminShopCouponController::class, 'toggleStatus']);
        Route::delete('/coupons/{id}', [AdminShopCouponController::class, 'destroy']);

        // ── Phase 10C: Admin Returns ──
        Route::get('/returns', [AdminShopReturnController::class, 'index']);
        Route::get('/returns/{id}', [AdminShopReturnController::class, 'show']);
        Route::post('/returns/{id}/approve', [AdminShopReturnController::class, 'approve']);
        Route::post('/returns/{id}/reject', [AdminShopReturnController::class, 'reject']);
        Route::post('/returns/{id}/mark-returned', [AdminShopReturnController::class, 'markReturned']);

        // ── Phase 10C: Admin Refunds ──
        Route::get('/refunds', [AdminShopRefundController::class, 'index']);
        Route::get('/refunds/{id}', [AdminShopRefundController::class, 'show']);
        Route::post('/refunds/{id}/update-status', [AdminShopRefundController::class, 'updateStatus']);

        // ── Phase 10C: Admin Product Reviews ──
        Route::get('/product-reviews', [AdminShopProductReviewController::class, 'index']);
        Route::post('/product-reviews/{review_id}/hide', [AdminShopProductReviewController::class, 'hide']);
        Route::post('/product-reviews/{review_id}/unhide', [AdminShopProductReviewController::class, 'unhide']);
    });

    // ============================================================
    // PHASE 10B: CUSTOMER CART, CHECKOUT & MY ORDERS
    // Requires auth:sanctum. No admin role needed.
    // ============================================================

    // Cart
    Route::get('/shop/cart', [ShopCartController::class, 'getCart']);
    Route::post('/shop/cart/items', [ShopCartController::class, 'addItem']);
    Route::post('/shop/cart/items/{cart_item_id}/update', [ShopCartController::class, 'updateItem']);
    Route::delete('/shop/cart/items/{cart_item_id}', [ShopCartController::class, 'removeItem']);
    Route::delete('/shop/cart/clear', [ShopCartController::class, 'clearCart']);

    // Phase 10C: Cart Coupon
    Route::post('/shop/cart/apply-coupon', [ShopCartController::class, 'applyCoupon']);
    Route::delete('/shop/cart/remove-coupon', [ShopCartController::class, 'removeCoupon']);

    // Checkout
    Route::post('/shop/checkout', [ShopCheckoutController::class, 'checkout']);

    // My Orders
    Route::get('/shop/my-orders', [ShopMyOrderController::class, 'index']);
    Route::get('/shop/my-orders/{order_id}', [ShopMyOrderController::class, 'show']);
    Route::post('/shop/my-orders/{order_id}/cancel', [ShopMyOrderController::class, 'cancel']);

    // Phase 10C: Customer Returns
    Route::post('/shop/my-orders/{order_id}/request-return', [ShopMyOrderController::class, 'requestReturn']);
    Route::get('/shop/my-returns', [ShopMyOrderController::class, 'myReturns']);
    Route::get('/shop/my-returns/{return_id}', [ShopMyOrderController::class, 'showReturn']);

    // Phase 10C: Customer Product Reviews
    Route::post('/shop/products/{product_id}/reviews', [ShopMyOrderController::class, 'submitReview']);

    // ============================================================
    // PHASE 11: IN-APP NOTIFICATIONS (Customer / User)
    // Database notifications with frontend polling. No SMS/email/push.
    // ============================================================
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications/clear-read', [NotificationController::class, 'clearRead']);
    Route::post('/notifications/{id}/mark-read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
});