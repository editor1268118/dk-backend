<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ServiceAvailability;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * Create a new booking from a service availability slot.
     */
    public function store(Request $request)
    {
        $request->validate([
            'service_id'              => 'required|exists:services,id',
            'service_availability_id' => 'required|exists:service_availabilities,id',
            'notes'                   => 'nullable|string|max:1000',
        ]);

        $slot = ServiceAvailability::where('id', $request->service_availability_id)
            ->where('service_id', $request->service_id)
            ->where('is_available', true)
            ->first();

        if (!$slot) {
            return response()->json(['message' => 'This slot is not available or does not belong to the selected service.'], 422);
        }

        // Create booking
        $booking = Booking::create([
            'user_id'          => $request->user()->id,
            'service_id'       => $slot->service_id,
            'provider_user_id' => $slot->provider_user_id,
            'booking_date'     => $slot->available_date,
            'booking_time'     => $slot->start_time,
            'status'           => 'Pending',
            'amount'           => $slot->service->price ?? 0,
            'notes'            => $request->notes,
        ]);

        // Mark slot as unavailable
        $slot->update(['is_available' => false]);

        $booking->load(['service:id,title,price', 'provider:id,name']);

        return response()->json([
            'message' => 'Booking submitted successfully.',
            'booking' => $booking,
        ], 201);
    }

    /**
     * List all bookings received by the authenticated professional provider.
     */
    public function professionalBookings(Request $request)
    {
        if (!$request->user()->hasRole('provider')) {
            return response()->json(['message' => 'Forbidden. You are not a professional provider.'], 403);
        }

        $bookings = Booking::with(['user:id,name,email', 'service:id,title,price'])
            ->where('provider_user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'bookings' => $bookings,
        ], 200);
    }
}
