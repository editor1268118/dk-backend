<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Service;
use App\Models\ServiceAvailability;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * Create a new booking from a service availability slot.
     */
    public function store(StoreBookingRequest $request)
    {
        $validated = $request->validated();
        
        $serviceId = $validated['service_id'];
        $slotId = $validated['service_availability_id'];
        
        // Validate service exists & active
        $service = Service::where('is_active', true)->find($serviceId);
        if (!$service) {
            return response()->json(['message' => 'Service not found or inactive.'], 404);
        }
        
        // Validate slot exists
        $slot = ServiceAvailability::find($slotId);
        if (!$slot) {
            return response()->json(['message' => 'Slot not found.'], 404);
        }

        // Validate that slot belongs to selected service
        if ($slot->service_id !== $service->id) {
            return response()->json(['message' => 'Slot does not belong to the selected service.'], 422);
        }

        // Validate that slot is available
        if (!$slot->is_available) {
            return response()->json(['message' => 'This slot is marked as unavailable.'], 422);
        }

        // Validate that slot belongs to correct provider/service
        if ($slot->provider_user_id !== $service->provider_user_id) {
            return response()->json(['message' => 'Provider mismatch error.'], 422);
        }

        // Prevent booking past slots
        $today = now()->toDateString();
        $currentTime = now()->toTimeString();
        if ($slot->available_date < $today || ($slot->available_date == $today && $slot->start_time <= $currentTime)) {
             return response()->json(['message' => 'Cannot book a slot in the past.'], 422);
        }

        // Validate that slot is not already booked (One booking per slot rule)
        $existingBooking = Booking::where('service_availability_id', $slotId)
            ->where('status', '!=', 'cancelled')
            ->exists();
            
        if ($existingBooking) {
            return response()->json(['message' => 'This slot is already booked.'], 422);
        }
        
        // Create booking (Auto-confirmed for phase 1)
        $booking = Booking::create([
            'user_id' => $request->user()->id,
            'service_id' => $service->id,
            'provider_user_id' => $service->provider_user_id,
            'service_availability_id' => $slot->id,
            'booking_date' => $slot->available_date,
            'booking_time' => $slot->start_time,
            'amount' => $service->price,
            'notes' => $validated['notes'] ?? null,
            'status' => 'confirmed',
        ]);
        
        // Mark slot as unavailable to definitively prevent double booking
        $slot->update(['is_available' => false]);
        
        return response()->json([
            'message' => 'Booking successfully confirmed.',
            'booking' => $booking->load(['service', 'provider', 'serviceAvailability'])
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

        $bookings = Booking::with(['user:id,name,email', 'service:id,title,price', 'serviceAvailability'])
            ->where('provider_user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'bookings' => $bookings,
        ], 200);
    }
}

