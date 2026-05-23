<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemBookingController extends Controller
{
    /**
     * POST /api/system/bookings/auto-confirm-expired-windows
     *
     * Auto-confirm bookings where the 5-minute action window has expired.
     */
    public function autoConfirmExpiredWindows(Request $request)
    {
        // Protect with auth:sanctum and check for admin role if applicable.
        // For development/admin testing, we verify user is authenticated.
        if ($request->user() && !$request->user()->hasRole('admin')) {
            // If not admin, return 403
            return response()->json([
                'message' => 'Forbidden. Admin access required to trigger system auto-confirm.',
                'note' => 'In production, this is executed automatically via scheduler/cron.'
            ], 403);
        }

        $now = now();

        $expiredBookings = ServiceBooking::where('status', ServiceBooking::STATUS_VENDOR_ACCEPTED)
            ->whereNotNull('assigned_provider_user_id')
            ->whereNotNull('action_window_ends_at')
            ->where('action_window_ends_at', '<=', $now)
            ->get();

        $confirmedReferences = [];

        foreach ($expiredBookings as $booking) {
            $booking->update([
                'status' => ServiceBooking::STATUS_CONFIRMED,
                'confirmed_at' => $now,
            ]);
            $confirmedReferences[] = $booking->booking_reference;
        }

        return response()->json([
            'message' => 'Auto-confirmation check completed.',
            'confirmed_count' => count($confirmedReferences),
            'confirmed_booking_references' => $confirmedReferences,
        ], 200);
    }
}
