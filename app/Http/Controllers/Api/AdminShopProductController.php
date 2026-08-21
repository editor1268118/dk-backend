<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductInventoryLog;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class AdminShopProductController extends Controller
{
    /**
     * Generate a unique slug from the product name.
     */
    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (true) {
            $query = Product::where('slug', $slug);
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

    // ================================================================
    // PRODUCT CRUD
    // ================================================================

    /**
     * List all products with filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        $query = Product::with(['category:id,name,slug']);

        // Search by name, sku, or short_description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('product_category_id', $request->input('category_id'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Filter by is_featured
        if ($request->has('is_featured')) {
            $query->where('is_featured', filter_var($request->input('is_featured'), FILTER_VALIDATE_BOOLEAN));
        }

        // Filter low stock products
        if ($request->has('low_stock') && filter_var($request->input('low_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSorts = ['id', 'name', 'price', 'stock_quantity', 'status', 'is_active', 'is_featured', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);

        $products = $query->paginate($perPage);

        // Add computed attributes
        $items = collect($products->items())->map(function ($product) {
            $product->effective_price = $product->effective_price;
            $product->in_stock = $product->in_stock;
            $product->is_low_stock = $product->is_low_stock;
            $product->main_image_url = $product->main_image_url;
            return $product;
        });

        return response()->json([
            'products' => $items,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ], 200);
    }

    /**
     * Create a new product.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_category_id' => 'nullable|exists:product_categories,id',
            'name'                => 'required|string|max:255',
            'sku'                 => 'nullable|string|max:100|unique:products,sku',
            'short_description'   => 'nullable|string|max:500',
            'description'         => 'nullable|string',
            'price'               => 'required|numeric|min:0',
            'sale_price'          => 'nullable|numeric|min:0|lte:price',
            'stock_quantity'      => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'weight'              => 'nullable|numeric|min:0',
            'unit'                => 'nullable|string|max:50',
            'is_featured'         => 'nullable|boolean',
            'is_active'           => 'nullable|boolean',
            'status'              => 'nullable|string|in:draft,published,inactive',
            'meta_title'          => 'nullable|string|max:255',
            'meta_description'    => 'nullable|string|max:500',
        ]);

        // Set defaults
        $validated['slug']               = $this->generateUniqueSlug($validated['name']);
        $validated['stock_quantity']      = $validated['stock_quantity'] ?? 0;
        $validated['low_stock_threshold'] = $validated['low_stock_threshold'] ?? 5;
        $validated['is_featured']         = $validated['is_featured'] ?? false;
        $validated['is_active']           = $validated['is_active'] ?? true;
        $validated['status']              = $validated['status'] ?? 'draft';

        $product = Product::create($validated);

        // Create inventory log for initial stock
        if ($product->stock_quantity > 0) {
            ProductInventoryLog::create([
                'product_id'      => $product->id,
                'change_type'     => ProductInventoryLog::TYPE_CREATE,
                'quantity_before'  => 0,
                'quantity_after'   => $product->stock_quantity,
                'quantity_changed' => $product->stock_quantity,
                'reason'          => 'Initial stock on product creation.',
                'admin_user_id'   => $request->user()->id,
            ]);
        }

        $product->load(['category:id,name,slug', 'images']);
        $product->effective_price = $product->effective_price;
        $product->in_stock = $product->in_stock;
        $product->main_image_url = $product->main_image_url;

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_created',
            'product',
            $product->id,
            "Product '{$product->name}' created (SKU: {$product->sku}).",
            $product->toArray()
        );

        return response()->json([
            'message' => 'Product created successfully.',
            'product' => $product,
        ], 201);
    }

    /**
     * Show a single product with full details.
     */
    public function show(Request $request, $id)
    {
        $product = Product::with([
            'category:id,name,slug',
            'images',
            'inventoryLogs' => function ($q) {
                $q->with('admin:id,name')->latest()->limit(20);
            },
        ])->find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $product->effective_price = $product->effective_price;
        $product->in_stock = $product->in_stock;
        $product->is_low_stock = $product->is_low_stock;
        $product->main_image_url = $product->main_image_url;

        return response()->json([
            'product' => $product,
        ], 200);
    }

    /**
     * Update an existing product.
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validated = $request->validate([
            'product_category_id' => 'nullable|exists:product_categories,id',
            'name'                => 'required|string|max:255',
            'sku'                 => 'nullable|string|max:100|unique:products,sku,' . $product->id,
            'short_description'   => 'nullable|string|max:500',
            'description'         => 'nullable|string',
            'price'               => 'required|numeric|min:0',
            'sale_price'          => 'nullable|numeric|min:0|lte:price',
            'stock_quantity'      => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'weight'              => 'nullable|numeric|min:0',
            'unit'                => 'nullable|string|max:50',
            'is_featured'         => 'nullable|boolean',
            'is_active'           => 'nullable|boolean',
            'status'              => 'nullable|string|in:draft,published,inactive',
            'meta_title'          => 'nullable|string|max:255',
            'meta_description'    => 'nullable|string|max:500',
        ]);

        $oldValues = $product->toArray();

        // Re-generate slug if name changed
        if ($validated['name'] !== $product->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $product->id);
        }

        // Track stock changes via update (if stock_quantity provided and different)
        if (isset($validated['stock_quantity']) && $validated['stock_quantity'] !== $product->stock_quantity) {
            $quantityBefore = $product->stock_quantity;
            $quantityAfter = $validated['stock_quantity'];

            ProductInventoryLog::create([
                'product_id'       => $product->id,
                'change_type'      => ProductInventoryLog::TYPE_STOCK_UPDATE,
                'quantity_before'  => $quantityBefore,
                'quantity_after'   => $quantityAfter,
                'quantity_changed' => $quantityAfter - $quantityBefore,
                'reason'          => 'Stock updated via product edit.',
                'admin_user_id'   => $request->user()->id,
            ]);
        }

        $product->update($validated);
        $product->load(['category:id,name,slug', 'images']);
        $product->effective_price = $product->effective_price;
        $product->in_stock = $product->in_stock;
        $product->main_image_url = $product->main_image_url;

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_updated',
            'product',
            $product->id,
            "Product '{$product->name}' updated.",
            null,
            $oldValues,
            $product->toArray()
        );

        return response()->json([
            'message' => 'Product updated successfully.',
            'product' => $product,
        ], 200);
    }

    /**
     * Toggle active status of a product.
     */
    public function toggleStatus(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $oldStatus = $product->is_active;
        $product->is_active = !$product->is_active;
        $product->save();

        $product->load('category:id,name,slug');

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_toggled',
            'product',
            $product->id,
            "Product '{$product->name}' " . ($product->is_active ? 'activated' : 'deactivated') . ".",
            null,
            ['is_active' => $oldStatus],
            ['is_active' => $product->is_active]
        );

        return response()->json([
            'message' => "Product is now " . ($product->is_active ? "active" : "inactive") . ".",
            'product' => $product,
        ], 200);
    }

    /**
     * Toggle featured status of a product.
     */
    public function toggleFeatured(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $oldFeatured = $product->is_featured;
        $product->is_featured = !$product->is_featured;
        $product->save();

        $product->load('category:id,name,slug');

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_featured_toggled',
            'product',
            $product->id,
            "Product '{$product->name}' " . ($product->is_featured ? 'marked as featured' : 'removed from featured') . ".",
            null,
            ['is_featured' => $oldFeatured],
            ['is_featured' => $product->is_featured]
        );

        return response()->json([
            'message' => "Product is now " . ($product->is_featured ? "featured" : "not featured") . ".",
            'product' => $product,
        ], 200);
    }

    /**
     * Delete a product.
     * Safe delete allowed if no dependent records.
     * In future when shop_orders exist, soft delete/deactivate instead.
     */
    public function destroy(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Future: check for shop_orders references
        // For now, safe delete allowed

        $productName = $product->name;
        $productId = $product->id;

        // Delete associated images from storage
        $images = $product->images;
        foreach ($images as $image) {
            if ($image->image_path && Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
            }
        }

        $product->delete();

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_deleted',
            'product',
            $productId,
            "Product '{$productName}' deleted."
        );

        return response()->json([
            'message' => 'Product deleted successfully.',
        ], 200);
    }

    // ================================================================
    // PRODUCT IMAGES
    // ================================================================

    /**
     * Upload a product image.
     */
    public function uploadImage(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validated = $request->validate([
            'image'      => 'required|image|mimes:jpeg,jpg,png,webp,gif|max:5120', // 5MB max
            'alt_text'   => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_primary' => 'nullable|boolean',
        ]);

        // Store the image
        $path = $request->file('image')->store('products', 'public');

        $isPrimary = filter_var($request->input('is_primary', false), FILTER_VALIDATE_BOOLEAN);

        // If this is set as primary, unset other primaries
        if ($isPrimary) {
            ProductImage::where('product_id', $product->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // If this is the first image, make it primary by default
        $existingImageCount = $product->images()->count();
        if ($existingImageCount === 0) {
            $isPrimary = true;
        }

        $image = ProductImage::create([
            'product_id' => $product->id,
            'image_path' => $path,
            'alt_text'   => $request->input('alt_text'),
            'sort_order' => $request->input('sort_order', 0),
            'is_primary' => $isPrimary,
        ]);

        // Update product main_image if this is primary
        if ($isPrimary) {
            $product->update(['main_image' => $path]);
        }

        $image->image_url = $image->image_url;

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_image_uploaded',
            'product',
            $product->id,
            "Image uploaded for product '{$product->name}'.",
            ['image_id' => $image->id, 'is_primary' => $isPrimary]
        );

        return response()->json([
            'message' => 'Product image uploaded successfully.',
            'image'   => $image,
        ], 201);
    }

    /**
     * Delete a product image.
     */
    public function deleteImage(Request $request, $id, $imageId)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $image = ProductImage::where('product_id', $product->id)
            ->where('id', $imageId)
            ->first();

        if (!$image) {
            return response()->json(['message' => 'Product image not found.'], 404);
        }

        $wasPrimary = $image->is_primary;

        // Delete from storage
        if ($image->image_path && Storage::disk('public')->exists($image->image_path)) {
            Storage::disk('public')->delete($image->image_path);
        }

        $image->delete();

        // If deleted image was primary, set the next image as primary
        if ($wasPrimary) {
            $nextImage = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')
                ->first();

            if ($nextImage) {
                $nextImage->update(['is_primary' => true]);
                $product->update(['main_image' => $nextImage->image_path]);
            } else {
                $product->update(['main_image' => null]);
            }
        }

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_image_deleted',
            'product',
            $product->id,
            "Image deleted from product '{$product->name}'.",
            ['image_id' => $imageId, 'was_primary' => $wasPrimary]
        );

        return response()->json([
            'message' => 'Product image deleted successfully.',
        ], 200);
    }

    /**
     * Set a product image as the primary image.
     */
    public function setPrimaryImage(Request $request, $id, $imageId)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $image = ProductImage::where('product_id', $product->id)
            ->where('id', $imageId)
            ->first();

        if (!$image) {
            return response()->json(['message' => 'Product image not found.'], 404);
        }

        // Unset all other primaries for this product
        ProductImage::where('product_id', $product->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);

        // Set this image as primary
        $image->update(['is_primary' => true]);

        // Update product main_image
        $product->update(['main_image' => $image->image_path]);

        $image->image_url = $image->image_url;

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_image_set_primary',
            'product',
            $product->id,
            "Image #{$imageId} set as primary for product '{$product->name}'.",
            ['image_id' => $imageId]
        );

        return response()->json([
            'message' => 'Image set as primary successfully.',
            'image'   => $image,
        ], 200);
    }

    // ================================================================
    // INVENTORY / STOCK ADJUSTMENT
    // ================================================================

    /**
     * Adjust product stock quantity.
     */
    public function stockAdjust(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validated = $request->validate([
            'stock_quantity' => 'required|integer|min:0',
            'reason'         => 'nullable|string|max:500',
        ]);

        $quantityBefore = $product->stock_quantity;
        $quantityAfter = $validated['stock_quantity'];
        $quantityChanged = $quantityAfter - $quantityBefore;

        // Update product stock
        $product->update(['stock_quantity' => $quantityAfter]);

        // Create inventory log
        $log = ProductInventoryLog::create([
            'product_id'       => $product->id,
            'change_type'      => ProductInventoryLog::TYPE_MANUAL_ADJUSTMENT,
            'quantity_before'  => $quantityBefore,
            'quantity_after'   => $quantityAfter,
            'quantity_changed' => $quantityChanged,
            'reason'           => $validated['reason'] ?? 'Manual stock adjustment.',
            'admin_user_id'    => $request->user()->id,
        ]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_stock_adjusted',
            'product',
            $product->id,
            "Stock adjusted for '{$product->name}': {$quantityBefore} → {$quantityAfter} (change: {$quantityChanged}).",
            [
                'quantity_before'  => $quantityBefore,
                'quantity_after'   => $quantityAfter,
                'quantity_changed' => $quantityChanged,
                'reason'           => $validated['reason'] ?? null,
            ]
        );

        $product->effective_price = $product->effective_price;
        $product->in_stock = $product->in_stock;
        $product->is_low_stock = $product->is_low_stock;

        return response()->json([
            'message'       => 'Stock quantity adjusted successfully.',
            'product'       => $product,
            'inventory_log' => $log,
        ], 200);
    }
}
