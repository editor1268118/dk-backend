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
use App\Http\Controllers\Api\PublicPlatformServiceController;
use App\Http\Controllers\Api\ProfessionalSelectedServiceController;
use App\Http\Controllers\Api\ProfessionalServiceAreaController;
use App\Http\Controllers\Api\ServiceBookingController;
use App\Http\Controllers\Api\VendorJobOfferController;
use App\Http\Controllers\Api\ProviderDashboardController;
use App\Http\Controllers\Api\SystemBookingController;
use App\Http\Controllers\Api\ProfessionalWalletController;

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
    Route::post('/professional/bookings/{id}/request-change-within-window', [ProviderDashboardController::class, 'requestChangeWithinWindow']);
    Route::post('/professional/bookings/{id}/cancel-after-confirmation', [ProviderDashboardController::class, 'cancelAfterConfirmation']);

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
});