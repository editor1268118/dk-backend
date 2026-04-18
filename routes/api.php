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

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // User Dashboard - Profile
    Route::get('/profile', [UserDashboardController::class, 'getProfile']);
    Route::post('/profile/update', [UserDashboardController::class, 'updateProfile']);

    // User Dashboard - Donations
    Route::get('/my/donations', [UserDashboardController::class, 'getDonations']);
    Route::get('/my/donations/summary', [UserDashboardController::class, 'getDonationSummary']);

    // User Dashboard - Bookings
    Route::get('/my/bookings', [UserDashboardController::class, 'getBookings']);
    Route::get('/my/bookings/summary', [UserDashboardController::class, 'getBookingSummary']);

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
});