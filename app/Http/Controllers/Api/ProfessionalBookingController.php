<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;

class ProfessionalBookingController extends Controller
{
    /**
     * Get bookings received by the professional.
     */
    public function index(Request $request)
    {
        $bookings = $request->user()->bookingsReceived()
            ->with([
                'user:id,name,email,phone',
                'service:id,title,slug,price',
                'serviceAvailability'
            ])
            ->orderBy('booking_date', 'desc')
            ->orderBy('booking_time', 'desc')
            ->get();

        return response()->json([
            'bookings' => $bookings
        ]);
    }

    /**
     * Get summary stats for the professional's bookings.
     */
    public function summary(Request $request)
    {
        $bookings = $request->user()->bookingsReceived;
        $today = now()->toDateString();

        $summary = [
            'total_bookings_count' => $bookings->count(),
            'total_confirmed_bookings_count' => $bookings->where('status', 'confirmed')->count(),
            'total_pending_bookings_count' => $bookings->where('status', 'pending')->count(),
            'total_cancelled_bookings_count' => $bookings->where('status', 'cancelled')->count(),
            'upcoming_bookings_count' => $bookings->where('booking_date', '>=', $today)
                                                   ->where('status', '!=', 'cancelled')
                                                   ->count(),
            'total_earnings' => $bookings->where('status', 'confirmed')->sum('amount'),
        ];

        return response()->json([
            'summary' => $summary
        ]);
    }
}
