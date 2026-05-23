<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformService;
use App\Models\ProviderSelectedService;
use Illuminate\Http\Request;

class ProfessionalSelectedServiceController extends Controller
{
    /**
     * Ensure the authenticated user has provider role.
     */
    private function ensureProvider(Request $request)
    {
        if (!$request->user()->hasRole('provider')) {
            abort(response()->json(['message' => 'Forbidden. You are not registered as a professional provider.'], 403));
        }
    }

    /**
     * List all active platform services available for selection by the provider.
     */
    public function availablePlatformServices(Request $request)
    {
        $this->ensureProvider($request);

        $services = PlatformService::with('category:id,name,slug')
            ->where('is_active', true)
            ->latest()
            ->get();

        return response()->json([
            'platform_services' => $services,
        ], 200);
    }

    /**
     * List the provider's currently selected services.
     */
    public function selectedServices(Request $request)
    {
        $this->ensureProvider($request);

        $selectedServices = ProviderSelectedService::with('platformService.category:id,name,slug')
            ->where('provider_user_id', $request->user()->id)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'selected_services' => $selectedServices,
        ], 200);
    }

    /**
     * Sync the provider's selected services.
     *
     * Accepts an array of platform_service_ids.
     * Creates new selections, re-activates previously deactivated ones,
     * and deactivates any that are no longer in the list.
     */
    public function sync(Request $request)
    {
        $this->ensureProvider($request);

        $validated = $request->validate([
            'service_ids'   => 'required|array',
            'service_ids.*' => 'integer|exists:platform_services,id',
        ]);

        $userId = $request->user()->id;
        $requestedIds = collect($validated['service_ids']);

        // Verify all requested services are active
        $activeServiceIds = PlatformService::whereIn('id', $requestedIds)
            ->where('is_active', true)
            ->pluck('id');

        $invalidIds = $requestedIds->diff($activeServiceIds);
        if ($invalidIds->isNotEmpty()) {
            return response()->json([
                'message' => 'Some services are not active or do not exist.',
                'invalid_service_ids' => $invalidIds->values(),
            ], 422);
        }

        // Deactivate services no longer selected
        ProviderSelectedService::where('provider_user_id', $userId)
            ->whereNotIn('platform_service_id', $activeServiceIds)
            ->update(['is_active' => false]);

        // Create or re-activate selected services
        foreach ($activeServiceIds as $serviceId) {
            ProviderSelectedService::updateOrCreate(
                [
                    'provider_user_id' => $userId,
                    'platform_service_id' => $serviceId,
                ],
                [
                    'is_active' => true,
                ]
            );
        }

        // Return the updated list
        $selectedServices = ProviderSelectedService::with('platformService.category:id,name,slug')
            ->where('provider_user_id', $userId)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'message' => 'Selected services synced successfully.',
            'selected_services' => $selectedServices,
        ], 200);
    }
}
