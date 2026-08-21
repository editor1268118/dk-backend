<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPlatformServiceController extends Controller
{
    /**
     * Ensure the authenticated user has admin role.
     */
    private function ensureAdmin(Request $request)
    {
        if (!$request->user()->hasRole('admin')) {
            abort(response()->json(['message' => 'Forbidden. Admin access required.'], 403));
        }
    }

    /**
     * Generate a unique slug from the service name.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = PlatformService::where('slug', $slug);
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
     * List all platform services (admin view — includes inactive).
     */
    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $services = PlatformService::with('category:id,name,slug')
            ->latest()
            ->get();

        return response()->json([
            'platform_services' => $services,
        ], 200);
    }

    /**
     * Create a new platform service.
     */
    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $validated = $request->validate([
            'service_category_id'    => 'nullable|exists:service_categories,id',
            'name'                   => 'required|string|max:255',
            'description'            => 'nullable|string',
            'customer_price'         => 'required|numeric|min:0',
            'vendor_payout_percentage' => 'required|numeric|min:0|max:100',
            'platform_percentage'    => 'nullable|numeric|min:0|max:100',
            'is_active'              => 'nullable|boolean',
            'requires_start_otp'     => 'nullable|boolean',
        ]);

        // Auto-calculate platform_percentage if not provided
        if (!isset($validated['platform_percentage'])) {
            $validated['platform_percentage'] = 100 - $validated['vendor_payout_percentage'];
        }

        $validated['slug'] = $this->generateUniqueSlug($validated['name']);
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['requires_start_otp'] = $validated['requires_start_otp'] ?? true;

        $service = PlatformService::create($validated);
        $service->load('category:id,name,slug');

        return response()->json([
            'message' => 'Platform service created successfully.',
            'platform_service' => $service,
        ], 201);
    }

    /**
     * Show a single platform service.
     */
    public function show(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $service = PlatformService::with('category:id,name,slug')->find($id);

        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        return response()->json([
            'platform_service' => $service,
        ], 200);
    }

    /**
     * Update an existing platform service.
     */
    public function update(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $service = PlatformService::find($id);

        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        $validated = $request->validate([
            'service_category_id'    => 'nullable|exists:service_categories,id',
            'name'                   => 'required|string|max:255',
            'description'            => 'nullable|string',
            'customer_price'         => 'required|numeric|min:0',
            'vendor_payout_percentage' => 'required|numeric|min:0|max:100',
            'platform_percentage'    => 'nullable|numeric|min:0|max:100',
            'is_active'              => 'nullable|boolean',
            'requires_start_otp'     => 'nullable|boolean',
        ]);

        // Auto-calculate platform_percentage if not provided
        if (!isset($validated['platform_percentage'])) {
            $validated['platform_percentage'] = 100 - $validated['vendor_payout_percentage'];
        }

        // Re-generate slug if name changed
        if ($validated['name'] !== $service->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $service->id);
        }

        $service->update($validated);
        $service->load('category:id,name,slug');

        return response()->json([
            'message' => 'Platform service updated successfully.',
            'platform_service' => $service,
        ], 200);
    }

    /**
     * Toggle active status of a platform service.
     */
    public function toggleStatus(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $service = PlatformService::find($id);

        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        $service->is_active = !$service->is_active;
        $service->save();

        $service->load('category:id,name,slug');

        return response()->json([
            'message' => "Platform service is now " . ($service->is_active ? "active" : "inactive") . ".",
            'platform_service' => $service,
        ], 200);
    }
}
