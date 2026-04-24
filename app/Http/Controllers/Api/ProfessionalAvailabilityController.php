<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAvailabilitySlotRequest;
use App\Http\Requests\Api\UpdateAvailabilitySlotRequest;
use App\Models\Service;
use App\Models\ServiceAvailability;
use Illuminate\Http\Request;

class ProfessionalAvailabilityController extends Controller
{
    /**
     * Helper to verify provider role and service ownership.
     */
    private function ensureServiceOwner(Request $request, $serviceId)
    {
        $user = $request->user();
        if (!$user->hasRole('provider')) {
            abort(response()->json(['message' => 'Forbidden. You are not registered as a professional provider.'], 403));
        }

        $service = Service::where('provider_user_id', $user->id)->find($serviceId);
        if (!$service) {
            abort(response()->json(['message' => 'Service not found or you do not have permission to manage slots for it.'], 404));
        }

        return $service;
    }

    /**
     * List all availability slots for the authenticated provider.
     */
    public function index(Request $request)
    {
        if (!$request->user()->hasRole('provider')) {
            return response()->json(['message' => 'Forbidden. You are not a professional provider.'], 403);
        }

        $slots = ServiceAvailability::with(['service:id,title,slug'])
            ->where('provider_user_id', $request->user()->id)
            ->orderBy('available_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'slots' => $slots,
        ], 200);
    }

    /**
     * Create a new availability slot.
     */
    public function store(StoreAvailabilitySlotRequest $request)
    {
        $this->ensureServiceOwner($request, $request->service_id);

        // Check for duplicate slot
        $exists = ServiceAvailability::where('service_id', $request->service_id)
            ->where('available_date', $request->available_date)
            ->where('start_time', $request->start_time)
            ->where('end_time', $request->end_time)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A slot with the same date and time already exists for this service.',
            ], 422);
        }

        $slot = ServiceAvailability::create([
            'service_id'       => $request->service_id,
            'provider_user_id' => $request->user()->id,
            'available_date'   => $request->available_date,
            'start_time'       => $request->start_time,
            'end_time'         => $request->end_time,
            'max_bookings'     => $request->max_bookings,
            'is_available'     => $request->has('is_available') ? $request->is_available : true,
            'notes'            => $request->notes,
        ]);

        $slot->load('service:id,title,slug');

        return response()->json([
            'message' => 'Availability slot created successfully.',
            'slot'    => $slot,
        ], 201);
    }

    /**
     * View a specific availability slot.
     */
    public function show(Request $request, $id)
    {
        $slot = ServiceAvailability::with(['service:id,title,slug'])
            ->where('provider_user_id', $request->user()->id)
            ->find($id);

        if (!$slot) {
            return response()->json(['message' => 'Availability slot not found or access denied.'], 404);
        }

        return response()->json([
            'slot' => $slot,
        ], 200);
    }

    /**
     * Update an availability slot.
     */
    public function update(UpdateAvailabilitySlotRequest $request, $id)
    {
        $slot = ServiceAvailability::where('provider_user_id', $request->user()->id)->find($id);

        if (!$slot) {
            return response()->json(['message' => 'Availability slot not found or access denied.'], 404);
        }

        // Verify ownership of the (potentially new) service_id
        $this->ensureServiceOwner($request, $request->service_id);

        // Update the slot
        $slot->update([
            'service_id'       => $request->service_id,
            'available_date'   => $request->available_date,
            'start_time'       => $request->start_time,
            'end_time'         => $request->end_time,
            'max_bookings'     => $request->max_bookings,
            'is_available'     => $request->has('is_available') ? $request->is_available : $slot->is_available,
            'notes'            => $request->notes,
        ]);

        $slot->load('service:id,title,slug');

        return response()->json([
            'message' => 'Availability slot updated successfully.',
            'slot'    => $slot,
        ], 200);
    }

    /**
     * Toggle the status of an availability slot.
     */
    public function toggleStatus(Request $request, $id)
    {
        $slot = ServiceAvailability::where('provider_user_id', $request->user()->id)->find($id);

        if (!$slot) {
            return response()->json(['message' => 'Availability slot not found or access denied.'], 404);
        }

        $slot->is_available = !$slot->is_available;
        $slot->save();

        return response()->json([
            'message' => 'Availability slot status toggled.',
            'slot'    => $slot,
        ], 200);
    }

    /**
     * Delete an availability slot.
     */
    public function destroy(Request $request, $id)
    {
        $slot = ServiceAvailability::where('provider_user_id', $request->user()->id)->find($id);

        if (!$slot) {
            return response()->json(['message' => 'Availability slot not found or access denied.'], 404);
        }

        $slot->delete();

        return response()->json([
            'message' => 'Availability slot deleted successfully.',
        ], 200);
    }

    /**
     * Public: list available (unbooked) slots for a given service (no auth required).
     */
    public function publicSlots($id)
    {
        $service = \App\Models\Service::where('is_active', true)->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found.'], 404);
        }

        $today = now()->toDateString();
        $currentTime = now()->toTimeString();

        $slots = ServiceAvailability::where('service_id', $id)
            ->where('is_available', true)
            ->where(function ($query) use ($today, $currentTime) {
                $query->where('available_date', '>', $today)
                      ->orWhere(function ($q) use ($today, $currentTime) {
                          $q->where('available_date', $today)
                            ->where('start_time', '>', $currentTime);
                      });
            })
            ->whereDoesntHave('bookings', function ($q) {
                $q->where('status', '!=', 'cancelled');
            })
            ->orderBy('available_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'slots' => $slots,
        ], 200);
    }
}
