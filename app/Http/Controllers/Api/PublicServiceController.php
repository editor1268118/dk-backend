<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceAvailability;
use Illuminate\Http\Request;

class PublicServiceController extends Controller
{
    public function index()
    {
        $services = Service::with([
                'provider:id,name,email',
                'category:id,name,slug'
            ])
            ->where('is_active', true)
            // also ensure provider is not suspended if you have such logic
            ->get();

        return response()->json([
            'services' => $services
        ]);
    }

    public function show($id)
    {
        $service = Service::with([
                'provider:id,name,email',
                'category:id,name,slug'
            ])
            ->where('is_active', true)
            ->findOrFail($id);

        return response()->json([
            'service' => $service
        ]);
    }

    public function getAvailableSlots($id)
    {
        // verify service exists and active
        $service = Service::where('is_active', true)->findOrFail($id);

        $today = now()->toDateString();
        $currentTime = now()->toTimeString();

        $slots = ServiceAvailability::where('service_id', $id)
            ->where('is_available', true)
            // only today future time or future dates
            ->where(function ($query) use ($today, $currentTime) {
                $query->where('available_date', '>', $today)
                      ->orWhere(function ($q) use ($today, $currentTime) {
                          $q->where('available_date', $today)
                            ->where('start_time', '>', $currentTime);
                      });
            })
            // Phase 1 rule: One booking per slot. Slot is unavailable if someone has booked it (unless cancelled)
            ->whereDoesntHave('bookings', function ($q) {
                $q->where('status', '!=', 'cancelled');
            })
            ->with([
                'service:id,title,slug,price,duration_minutes',
                'provider:id,name,email'
            ])
            ->orderBy('available_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->get();

        return response()->json([
            'slots' => $slots
        ]);
    }
}
