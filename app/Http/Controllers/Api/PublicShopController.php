<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class PublicShopController extends Controller
{
    /**
     * List active product categories for the public shop.
     * Returns only active categories with their active children.
     */
    public function categories(Request $request)
    {
        $categories = ProductCategory::where('is_active', true)
            ->with(['children' => function ($q) {
                $q->where('is_active', true)
                  ->orderBy('sort_order')
                  ->select('id', 'parent_id', 'name', 'slug', 'description', 'image', 'icon', 'sort_order');
            }])
            ->whereNull('parent_id') // Root categories only
            ->orderBy('sort_order')
            ->get([
                'id', 'name', 'slug', 'description', 'image', 'icon', 'parent_id', 'sort_order',
            ]);

        return response()->json([
            'categories' => $categories,
        ], 200);
    }

    /**
     * List published and active products for the public shop.
     * Supports filtering, search, and pagination.
     */
    public function products(Request $request)
    {
        $query = Product::where('status', Product::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->with(['category:id,name,slug']);

        // Search by name or short_description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('short_description', 'like', "%{$search}%");
            });
        }

        // Filter by category
        if ($request->has('category_id')) {
            $query->where('product_category_id', $request->input('category_id'));
        }

        // Filter by price range
        if ($request->has('min_price')) {
            $minPrice = (float) $request->input('min_price');
            $query->where(function ($q) use ($minPrice) {
                $q->where(function ($sub) use ($minPrice) {
                    $sub->whereNotNull('sale_price')
                        ->where('sale_price', '>=', $minPrice);
                })->orWhere(function ($sub) use ($minPrice) {
                    $sub->whereNull('sale_price')
                        ->where('price', '>=', $minPrice);
                });
            });
        }

        if ($request->has('max_price')) {
            $maxPrice = (float) $request->input('max_price');
            $query->where(function ($q) use ($maxPrice) {
                $q->where(function ($sub) use ($maxPrice) {
                    $sub->whereNotNull('sale_price')
                        ->where('sale_price', '<=', $maxPrice);
                })->orWhere(function ($sub) use ($maxPrice) {
                    $sub->whereNull('sale_price')
                        ->where('price', '<=', $maxPrice);
                });
            });
        }

        // Filter featured only
        if ($request->has('featured') && filter_var($request->input('featured'), FILTER_VALIDATE_BOOLEAN)) {
            $query->where('is_featured', true);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSorts = ['name', 'price', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);

        $products = $query->paginate($perPage);

        // Shape the public response
        $items = collect($products->items())->map(function ($product) {
            return [
                'id'                => $product->id,
                'name'              => $product->name,
                'slug'              => $product->slug,
                'category'          => $product->category ? [
                    'id'   => $product->category->id,
                    'name' => $product->category->name,
                    'slug' => $product->category->slug,
                ] : null,
                'short_description' => $product->short_description,
                'price'             => $product->price,
                'sale_price'        => $product->sale_price,
                'effective_price'   => $product->effective_price,
                'stock_quantity'    => $product->stock_quantity,
                'in_stock'          => $product->in_stock,
                'main_image_url'    => $product->main_image_url,
                'is_featured'       => $product->is_featured,
            ];
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
     * Show full product detail by slug.
     * Returns product info, category, images, pricing, stock, and related products.
     */
    public function productDetail(Request $request, $slug)
    {
        $product = Product::where('slug', $slug)
            ->where('status', Product::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->with([
                'category:id,name,slug',
                'images' => function ($q) {
                    $q->orderBy('sort_order');
                },
            ])
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        // Build image list with URLs
        $images = $product->images->map(function ($img) {
            return [
                'id'         => $img->id,
                'image_url'  => $img->image_url,
                'alt_text'   => $img->alt_text,
                'sort_order' => $img->sort_order,
                'is_primary' => $img->is_primary,
            ];
        });

        // Fetch related products from the same category (exclude current product)
        $relatedProducts = [];
        if ($product->product_category_id) {
            $relatedProducts = Product::where('product_category_id', $product->product_category_id)
                ->where('id', '!=', $product->id)
                ->where('status', Product::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->limit(6)
                ->get()
                ->map(function ($p) {
                    return [
                        'id'              => $p->id,
                        'name'            => $p->name,
                        'slug'            => $p->slug,
                        'price'           => $p->price,
                        'sale_price'      => $p->sale_price,
                        'effective_price' => $p->effective_price,
                        'in_stock'        => $p->in_stock,
                        'main_image_url'  => $p->main_image_url,
                        'is_featured'     => $p->is_featured,
                    ];
                });
        }

        // Fetch latest visible reviews
        $latestReviews = \App\Models\ProductReview::where('product_id', $product->id)
            ->where('is_visible', true)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(function ($review) {
                return [
                    'id'                => $review->id,
                    'rating'            => $review->rating,
                    'comment'           => $review->comment,
                    'customer_name'     => $review->user?->name,
                    'verified_purchase' => $review->verified_purchase,
                    'created_at'        => $review->created_at,
                ];
            });

        $productDetail = [
            'id'                => $product->id,
            'name'              => $product->name,
            'slug'              => $product->slug,
            'sku'               => $product->sku,
            'category'          => $product->category ? [
                'id'   => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'short_description' => $product->short_description,
            'description'       => $product->description,
            'price'             => $product->price,
            'sale_price'        => $product->sale_price,
            'effective_price'   => $product->effective_price,
            'stock_quantity'    => $product->stock_quantity,
            'in_stock'          => $product->in_stock,
            'weight'            => $product->weight,
            'unit'              => $product->unit,
            'main_image_url'    => $product->main_image_url,
            'images'            => $images,
            'is_featured'       => $product->is_featured,
            'meta_title'        => $product->meta_title,
            'meta_description'  => $product->meta_description,
            'average_rating'    => $product->average_rating,
            'total_reviews'     => $product->total_reviews,
            'created_at'        => $product->created_at,
        ];

        return response()->json([
            'product'          => $productDetail,
            'related_products' => $relatedProducts,
            'latest_reviews'   => $latestReviews,
        ], 200);
    }
}
