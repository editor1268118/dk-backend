<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminShopProductReviewController extends Controller
{
    /**
     * GET /api/admin/shop/product-reviews
     * List all product reviews with filters and pagination.
     */
    public function index(Request $request)
    {
        $query = ProductReview::with([
            'user:id,name,email',
            'product:id,name,slug,main_image',
            'shopOrder:id,order_number',
        ]);

        // Filters
        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('visible')) {
            $query->where('is_visible', filter_var($request->visible, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%");
                  })
                  ->orWhereHas('product', function ($pq) use ($search) {
                      $pq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $query->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 20), 100);
        $reviews = $query->paginate($perPage);

        $items = collect($reviews->items())->map(function ($review) {
            return [
                'id'                => $review->id,
                'product'           => $review->product ? [
                    'id'   => $review->product->id,
                    'name' => $review->product->name,
                    'slug' => $review->product->slug,
                ] : null,
                'customer'          => [
                    'id'   => $review->user?->id,
                    'name' => $review->user?->name,
                ],
                'order_number'      => $review->shopOrder?->order_number,
                'rating'            => $review->rating,
                'comment'           => $review->comment,
                'is_visible'        => $review->is_visible,
                'verified_purchase' => $review->verified_purchase,
                'created_at'        => $review->created_at,
            ];
        });

        return response()->json([
            'reviews' => $items,
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'per_page'     => $reviews->perPage(),
                'total'        => $reviews->total(),
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/product-reviews/{reviewId}/hide
     * Hide a product review.
     */
    public function hide(Request $request, $reviewId)
    {
        $review = ProductReview::find($reviewId);

        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $review->update(['is_visible' => false]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_review_hidden',
            'product_review',
            $review->id,
            "Product review #{$review->id} hidden.",
            null,
            ['is_visible' => true],
            ['is_visible' => false]
        );

        return response()->json([
            'message' => 'Review hidden.',
            'review'  => [
                'id'         => $review->id,
                'is_visible' => $review->is_visible,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/product-reviews/{reviewId}/unhide
     * Unhide a product review.
     */
    public function unhide(Request $request, $reviewId)
    {
        $review = ProductReview::find($reviewId);

        if (!$review) {
            return response()->json(['message' => 'Review not found.'], 404);
        }

        $review->update(['is_visible' => true]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'product_review_unhidden',
            'product_review',
            $review->id,
            "Product review #{$review->id} unhidden.",
            null,
            ['is_visible' => false],
            ['is_visible' => true]
        );

        return response()->json([
            'message' => 'Review unhidden.',
            'review'  => [
                'id'         => $review->id,
                'is_visible' => $review->is_visible,
            ],
        ], 200);
    }
}
