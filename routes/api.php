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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Password Reset (public)
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Public Service Routes (no auth required)
Route::get('/services', [ProfessionalServiceController::class, 'publicIndex']);
Route::get('/services/{id}', [ProfessionalServiceController::class, 'publicShow']);
Route::get('/services/{id}/available-slots', [ProfessionalAvailabilityController::class, 'publicSlots']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    
    // Bookings
    Route::post('/bookings', [BookingController::class, 'store']);

    // User Dashboard - Profile
    Route::get('/profile', [UserDashboardController::class, 'getProfile']);
    Route::post('/profile/update', [UserDashboardController::class, 'updateProfile']);

    // User Dashboard - Donations
    Route::get('/my/donations', [UserDashboardController::class, 'getDonations']);
    Route::get('/my/donations/summary', [UserDashboardController::class, 'getDonationSummary']);

    // User Dashboard - Bookings
    Route::get('/my/bookings', [UserDashboardController::class, 'getBookings']);
    Route::get('/my/bookings/summary', [UserDashboardController::class, 'getBookingSummary']);

    // Professional - Bookings Received
    Route::get('/professional/bookings', [BookingController::class, 'professionalBookings']);
    Route::get('/professional/bookings/summary', [ProfessionalBookingController::class, 'summary']);

    // Professional Registration & Profile
    Route::post('/register/professional', [ProfessionalController::class, 'registerProfessional']);
    Route::get('/professional/profile', [ProfessionalController::class, 'getProfile']);
    Route::post('/professional/profile/update', [ProfessionalController::class, 'updateProfile']);

    // Professional - My Services
    Route::get('/professional/services', [ProfessionalServiceController::class, 'index']);
    Route::post('/professional/services', [ProfessionalServiceController::class, 'store']);
    Route::get('/professional/services/{id}', [ProfessionalServiceController::class, 'show']);
    Route::post('/professional/services/{id}/update', [ProfessionalServiceController::class, 'update']);
    Route::post('/professional/services/{id}/toggle-status', [ProfessionalServiceController::class, 'toggleStatus']);

    // Service Categories
    Route::get('/service-categories', [ServiceCategoryController::class, 'index']);

    // Professional - Availability Slots
    Route::get('/professional/availability-slots', [ProfessionalAvailabilityController::class, 'index']);
    Route::post('/professional/availability-slots', [ProfessionalAvailabilityController::class, 'store']);
    Route::get('/professional/availability-slots/{id}', [ProfessionalAvailabilityController::class, 'show']);
    Route::post('/professional/availability-slots/{id}/update', [ProfessionalAvailabilityController::class, 'update']);
    Route::post('/professional/availability-slots/{id}/toggle-status', [ProfessionalAvailabilityController::class, 'toggleStatus']);
    Route::delete('/professional/availability-slots/{id}', [ProfessionalAvailabilityController::class, 'destroy']);
});