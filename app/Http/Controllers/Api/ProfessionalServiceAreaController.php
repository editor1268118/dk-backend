<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProviderServiceArea;
use Illuminate\Http\Request;

class ProfessionalServiceAreaController extends Controller
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
     * Validation rules for service area create/update.
     */
    private function validationRules(): array
    {
        return [
            'country'   => 'nullable|string|max:255',
            'state'     => 'nullable|string|max:255',
            'city'      => 'nullable|string|max:255',
            'pincode'   => 'nullable|string|max:50',
            'latitude'  => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'radius_km' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    /**
     * List all service areas for the authenticated provider.
     */
    public function index(Request $request)
    {
        $this->ensureProvider($request);

        $areas = ProviderServiceArea::where('provider_user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'service_areas' => $areas,
        ], 200);
    }

    /**
     * Create a new service area.
     */
    public function store(Request $request)
    {
        $this->ensureProvider($request);

        $validated = $request->validate($this->validationRules());
        $validated['provider_user_id'] = $request->user()->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $area = ProviderServiceArea::create($validated);

        return response()->json([
            'message' => 'Service area created successfully.',
            'service_area' => $area,
        ], 201);
    }

    /**
     * Show a single service area.
     */
    public function show(Request $request, $id)
    {
        $this->ensureProvider($request);

        $area = ProviderServiceArea::where('provider_user_id', $request->user()->id)
            ->find($id);

        if (!$area) {
            return response()->json(['message' => 'Service area not found or you do not have permission to view it.'], 404);
        }

        return response()->json([
            'service_area' => $area,
        ], 200);
    }

    /**
     * Update an existing service area.
     */
    public function update(Request $request, $id)
    {
        $this->ensureProvider($request);

        $area = ProviderServiceArea::where('provider_user_id', $request->user()->id)
            ->find($id);

        if (!$area) {
            return response()->json(['message' => 'Service area not found or you do not have permission to update it.'], 404);
        }

        $validated = $request->validate($this->validationRules());
        $area->update($validated);

        return response()->json([
            'message' => 'Service area updated successfully.',
            'service_area' => $area,
        ], 200);
    }

    /**
     * Delete a service area.
     */
    public function destroy(Request $request, $id)
    {
        $this->ensureProvider($request);

        $area = ProviderServiceArea::where('provider_user_id', $request->user()->id)
            ->find($id);

        if (!$area) {
            return response()->json(['message' => 'Service area not found or you do not have permission to delete it.'], 404);
        }

        $area->delete();

        return response()->json([
            'message' => 'Service area deleted successfully.',
        ], 200);
    }
}
