<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreProfessionalServiceRequest;
use App\Http\Requests\Api\UpdateProfessionalServiceRequest;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfessionalServiceController extends Controller
{
    /**
     * Helper to verify provider role.
     */
    private function ensureProvider(Request $request)
    {
        if (!$request->user()->hasRole('provider')) {
            abort(response()->json(['message' => 'Forbidden. You are not registered as a professional provider.'], 403));
        }
    }

    /**
     * Generate unique slug for service.
     */
    private function generateUniqueSlug($title, $ignoreId = null)
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = clone Service::query();
            $query->where('slug', $slug);
            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
            if (!$query->exists()) {
                break;
            }
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * List all services for the authenticated provider.
     */
    public function index(Request $request)
    {
        $this->ensureProvider($request);

        $services = Service::with(['category:id,name,slug'])
            ->where('provider_user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'services' => $services,
        ], 200);
    }

    /**
     * Create a new service.
     */
    public function store(StoreProfessionalServiceRequest $request)
    {
        $this->ensureProvider($request);

        $slug = $this->generateUniqueSlug($request->title);

        $service = Service::create([
            'provider_user_id'    => $request->user()->id,
            'service_category_id' => $request->service_category_id,
            'title'               => $request->title,
            'slug'                => $slug,
            'short_description'   => $request->short_description,
            'description'         => $request->description,
            'price'               => $request->price ?? 0.00,
            'pricing_type'        => $request->pricing_type ?? 'fixed',
            'duration_minutes'    => $request->duration_minutes,
            'is_active'           => $request->has('is_active') ? $request->is_active : true,
        ]);

        $service->load('category:id,name,slug');

        return response()->json([
            'message' => 'Service created successfully.',
            'service' => $service,
        ], 201);
    }

    /**
     * View one specific service of authenticated provider.
     */
    public function show(Request $request, $id)
    {
        $this->ensureProvider($request);

        $service = Service::with(['category:id,name,slug'])
            ->where('provider_user_id', $request->user()->id)
            ->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found or you do not have permission to view it.'], 404);
        }

        return response()->json([
            'service' => $service,
        ], 200);
    }

    /**
     * Update one specific service.
     */
    public function update(UpdateProfessionalServiceRequest $request, $id)
    {
        $this->ensureProvider($request);

        $service = Service::where('provider_user_id', $request->user()->id)->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found or you do not have permission to update it.'], 404);
        }

        $slug = $service->slug;
        if ($request->title !== $service->title) {
            $slug = $this->generateUniqueSlug($request->title, $service->id);
        }

        $service->update([
            'service_category_id' => $request->service_category_id,
            'title'               => $request->title,
            'slug'                => $slug,
            'short_description'   => $request->short_description,
            'description'         => $request->description,
            'price'               => $request->price ?? $service->price,
            'pricing_type'        => $request->pricing_type ?? $service->pricing_type,
            'duration_minutes'    => $request->duration_minutes !== null ? $request->duration_minutes : $service->duration_minutes,
            'is_active'           => $request->has('is_active') ? $request->is_active : $service->is_active,
        ]);

        $service->load('category:id,name,slug');

        return response()->json([
            'message' => 'Service updated successfully.',
            'service' => $service,
        ], 200);
    }

    /**
     * Toggle the active status of a service.
     */
    public function toggleStatus(Request $request, $id)
    {
        $this->ensureProvider($request);

        $service = Service::where('provider_user_id', $request->user()->id)->find($id);

        if (!$service) {
            return response()->json(['message' => 'Service not found or you do not have permission to modify it.'], 404);
        }

        $service->is_active = !$service->is_active;
        $service->save();

        $service->load('category:id,name,slug');

        return response()->json([
            'message' => "Service is now " . ($service->is_active ? "active" : "inactive") . ".",
            'service' => $service,
        ], 200);
    }
}
