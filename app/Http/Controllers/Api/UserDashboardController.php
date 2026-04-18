<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UserDashboardController extends Controller
{
    // ==========================================
    // PROFILE ENDPOINTS
    // ==========================================

    public function getProfile(Request $request)
    {
        $user = $request->user()->load('userProfile');
        
        return response()->json([
            'user' => $user
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();
        
        // Get or create user profile
        $userProfile = $user->userProfile()->firstOrCreate([]);
        
        $data = $request->validated();
        
        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            // Option to delete old image if required, but staying safe and keeping it simple
            $path = $request->file('profile_image')->store('profiles', 'public');
            $data['profile_image'] = $path;
        }

        $userProfile->update($data);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->load('userProfile')
        ]);
    }

    // ==========================================
    // DONATION ENDPOINTS
    // ==========================================

    public function getDonations(Request $request)
    {
        $donations = $request->user()->donationsMade()
            ->with(['fundraiser:id,name,email']) // Load basic info
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json([
            'donations' => $donations
        ]);
    }

    public function getDonationSummary(Request $request)
    {
        $donations = $request->user()->donationsMade;
        
        $summary = [
            'total_donations_count' => $donations->count(),
            'total_successful_donations_count' => $donations->where('payment_status', 'successful')->count(),
            'total_pending_donations_count' => $donations->where('payment_status', 'pending')->count(),
            'total_donation_amount' => $donations->sum('amount'),
            'successful_donation_amount' => $donations->where('payment_status', 'successful')->sum('amount'),
            'latest_donation_date' => $donations->max('created_at'),
        ];
        
        return response()->json([
            'summary' => $summary
        ]);
    }

    // ==========================================
    // BOOKING ENDPOINTS
    // ==========================================

    public function getBookings(Request $request)
    {
        $bookings = $request->user()->bookings()
            ->with([
                'service:id,title,slug,short_description,price,service_category_id',
                'service.category:id,name,slug', // Adjust basic info as per model fields
                'provider:id,name,email'
            ])
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_time', 'desc')
            ->get();
            
        return response()->json([
            'bookings' => $bookings
        ]);
    }

    public function getBookingSummary(Request $request)
    {
        $bookings = $request->user()->bookings;
        
        $today = now()->toDateString();
        
        $summary = [
            'total_bookings_count' => $bookings->count(),
            'total_pending_bookings_count' => $bookings->where('status', 'pending')->count(),
            'total_completed_bookings_count' => $bookings->where('status', 'completed')->count(),
            'total_cancelled_bookings_count' => $bookings->where('status', 'cancelled')->count(),
            'upcoming_bookings_count' => $bookings->where('booking_date', '>=', $today)
                                                  ->where('status', '!=', 'cancelled')
                                                  ->count(),
            'total_booking_amount' => $bookings->sum('amount'),
        ];
        
        return response()->json([
            'summary' => $summary
        ]);
    }
}
