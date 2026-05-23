<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformService;
use Illuminate\Http\Request;

class PublicPlatformServiceController extends Controller
{
    /**
     * List all active platform services (public — no auth required).
     * Will be used by customer booking page.
     */
    public function index()
    {
        $services = PlatformService::with('category:id,name,slug')
            ->where('is_active', true)
            ->latest()
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'description' => $service->description,
                    'customer_price' => $service->customer_price,
                    'vendor_payout_percentage' => $service->vendor_payout_percentage,
                    'platform_percentage' => $service->platform_percentage,
                    'category' => $service->category,
                ];
            });

        return response()->json([
            'platform_services' => $services,
        ], 200);
    }

    /**
     * Show a single active platform service (public — no auth required).
     */
    public function show($id)
    {
        $service = PlatformService::with('category:id,name,slug')
            ->where('is_active', true)
            ->find($id);

        if (!$service) {
            return response()->json(['message' => 'Platform service not found.'], 404);
        }

        return response()->json([
            'platform_service' => [
                'id' => $service->id,
                'name' => $service->name,
                'slug' => $service->slug,
                'description' => $service->description,
                'customer_price' => $service->customer_price,
                'vendor_payout_percentage' => $service->vendor_payout_percentage,
                'platform_percentage' => $service->platform_percentage,
                'category' => $service->category,
            ],
        ], 200);
    }
}
