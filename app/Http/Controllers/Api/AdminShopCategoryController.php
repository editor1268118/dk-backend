<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminShopCategoryController extends Controller
{
    /**
     * Generate a unique slug from the category name.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = ProductCategory::where('slug', $slug);
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
     * List all product categories with filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        $query = ProductCategory::with(['parent:id,name,slug', 'children:id,parent_id,name,slug,is_active']);

        // Search by name or description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by parent_id (null = root categories, numeric = specific parent)
        if ($request->has('parent_id')) {
            $parentId = $request->input('parent_id');
            if ($parentId === 'null' || $parentId === '') {
                $query->whereNull('parent_id');
            } else {
                $query->where('parent_id', $parentId);
            }
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'sort_order');
        $sortDirection = $request->input('sort_direction', 'asc');
        $allowedSorts = ['id', 'name', 'sort_order', 'is_active', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);

        $categories = $query->paginate($perPage);

        return response()->json([
            'product_categories' => $categories->items(),
            'pagination' => [
                'current_page' => $categories->currentPage(),
                'last_page'    => $categories->lastPage(),
                'per_page'     => $categories->perPage(),
                'total'        => $categories->total(),
            ],
        ], 200);
    }

    /**
     * Create a new product category.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'image'       => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:255',
            'parent_id'   => 'nullable|exists:product_categories,id',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        $validated['slug']       = $this->generateUniqueSlug($validated['name']);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['is_active']  = $validated['is_active'] ?? true;

        $category = ProductCategory::create($validated);
        $category->load(['parent:id,name,slug', 'children:id,parent_id,name,slug,is_active']);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_category_created',
            'product_category',
            $category->id,
            "Product category '{$category->name}' created.",
            $category->toArray()
        );

        return response()->json([
            'message'          => 'Product category created successfully.',
            'product_category' => $category,
        ], 201);
    }

    /**
     * Show a single product category.
     */
    public function show(Request $request, $id)
    {
        $category = ProductCategory::with([
            'parent:id,name,slug',
            'children:id,parent_id,name,slug,is_active,sort_order',
        ])->find($id);

        if (!$category) {
            return response()->json(['message' => 'Product category not found.'], 404);
        }

        // Include product count
        $category->products_count = $category->products()->count();

        return response()->json([
            'product_category' => $category,
        ], 200);
    }

    /**
     * Update an existing product category.
     */
    public function update(Request $request, $id)
    {
        $category = ProductCategory::find($id);

        if (!$category) {
            return response()->json(['message' => 'Product category not found.'], 404);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'image'       => 'nullable|string|max:500',
            'icon'        => 'nullable|string|max:255',
            'parent_id'   => 'nullable|exists:product_categories,id',
            'sort_order'  => 'nullable|integer|min:0',
            'is_active'   => 'nullable|boolean',
        ]);

        // Prevent self-parenting
        if (isset($validated['parent_id']) && $validated['parent_id'] == $id) {
            return response()->json(['message' => 'A category cannot be its own parent.'], 422);
        }

        $oldValues = $category->toArray();

        // Re-generate slug if name changed
        if ($validated['name'] !== $category->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $category->id);
        }

        $category->update($validated);
        $category->load(['parent:id,name,slug', 'children:id,parent_id,name,slug,is_active']);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_category_updated',
            'product_category',
            $category->id,
            "Product category '{$category->name}' updated.",
            null,
            $oldValues,
            $category->toArray()
        );

        return response()->json([
            'message'          => 'Product category updated successfully.',
            'product_category' => $category,
        ], 200);
    }

    /**
     * Toggle active status of a product category.
     */
    public function toggleStatus(Request $request, $id)
    {
        $category = ProductCategory::find($id);

        if (!$category) {
            return response()->json(['message' => 'Product category not found.'], 404);
        }

        $oldStatus = $category->is_active;
        $category->is_active = !$category->is_active;
        $category->save();

        $category->load(['parent:id,name,slug', 'children:id,parent_id,name,slug,is_active']);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_category_toggled',
            'product_category',
            $category->id,
            "Product category '{$category->name}' " . ($category->is_active ? 'activated' : 'deactivated') . ".",
            null,
            ['is_active' => $oldStatus],
            ['is_active' => $category->is_active]
        );

        return response()->json([
            'message'          => "Product category is now " . ($category->is_active ? "active" : "inactive") . ".",
            'product_category' => $category,
        ], 200);
    }

    /**
     * Delete a product category.
     * If category has products, deactivate instead of hard-deleting.
     */
    public function destroy(Request $request, $id)
    {
        $category = ProductCategory::find($id);

        if (!$category) {
            return response()->json(['message' => 'Product category not found.'], 404);
        }

        // Check if category has products
        $productCount = $category->products()->count();
        if ($productCount > 0) {
            // Deactivate instead of deleting
            $category->is_active = false;
            $category->save();

            AdminActionLog::record(
                $request->user()->id,
                'product_category_deactivated_instead_of_deleted',
                'product_category',
                $category->id,
                "Product category '{$category->name}' has {$productCount} product(s). Deactivated instead of deleted."
            );

            return response()->json([
                'message' => "Category has {$productCount} product(s) and cannot be deleted. It has been deactivated instead.",
                'product_category' => $category,
            ], 409);
        }

        // Check if category has children
        $childCount = $category->children()->count();
        if ($childCount > 0) {
            return response()->json([
                'message' => "Category has {$childCount} sub-category(ies) and cannot be deleted. Remove or reassign child categories first.",
            ], 409);
        }

        $categoryName = $category->name;
        $categoryId = $category->id;
        $category->delete();

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_category_deleted',
            'product_category',
            $categoryId,
            "Product category '{$categoryName}' deleted."
        );

        return response()->json([
            'message' => 'Product category deleted successfully.',
        ], 200);
    }
}
