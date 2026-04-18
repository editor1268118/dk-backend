<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\Request;

class ServiceCategoryController extends Controller
{
    /**
     * Retrieve a list of all active service categories.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        // Only return currently supported/active categories, mapped cleanly ascending by name.
        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'description']);

        return response()->json([
            'categories' => $categories
        ], 200);
    }
}
