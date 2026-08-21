<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopReturnRequest;
use App\Models\ShopReturnItem;
use App\Models\ShopOrderStatusHistory;
use App\Models\ProductReview;
use App\Models\Product;
use App\Models\ProductInventoryLog;
use Illuminate\Http\Request;

class ShopMyOrderController extends Controller
{
    /**
     * GET /api/shop/my-orders
     * List paginated orders for the authenticated user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = ShopOrder::where('user_id', $user->id)
            ->with(['items' => function ($q) {
                $q->select('id', 'shop_order_id', 'product_name', 'product_image', 'quantity', 'unit_price', 'line_total');
            }])
            ->orderByDesc('created_at');

        $perPage = min((int) $request->input('per_page', 15), 50);
        $orders = $query->paginate($perPage);

        $items = collect($orders->items())->map(function ($order) {
            $firstItem = $order->items->first();
            return [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'total_amount'   => $order->total_amount,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
                'return_status'  => $order->return_status,
                'refund_status'  => $order->refund_status,
                'coupon_code'    => $order->coupon_code,
                'discount_amount'=> $order->discount_amount,
                'item_count'     => $order->items->count(),
                'first_product_image_url' => $firstItem ? (
                    $firstItem->product_image
                        ? (str_starts_with($firstItem->product_image, 'http')
                            ? $firstItem->product_image
                            : asset('storage/' . $firstItem->product_image))
                        : null
                ) : null,
                'created_at'     => $order->created_at,
            ];
        });

        return response()->json([
            'orders' => $items,
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page'    => $orders->lastPage(),
                'per_page'     => $orders->perPage(),
                'total'        => $orders->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/shop/my-orders/{orderId}
     * Show full order detail (user can only view their own).
     */
    public function show(Request $request, $orderId)
    {
        $user = $request->user();

        $order = ShopOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with(['items', 'statusHistories', 'returnRequests.items', 'refunds'])
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $items = $order->items->map(function ($item) {
            return [
                'id'                => $item->id,
                'product_id'        => $item->product_id,
                'product_name'      => $item->product_name,
                'product_sku'       => $item->product_sku,
                'product_image_url' => $item->product_image_url,
                'quantity'          => $item->quantity,
                'unit_price'        => $item->unit_price,
                'line_total'        => $item->line_total,
            ];
        });

        // Build status timeline from status histories
        $statusHistories = $order->statusHistories->map(function ($h) {
            return [
                'old_status'      => $h->old_status,
                'new_status'      => $h->new_status,
                'note'            => $h->note,
                'changed_by_role' => $h->changed_by_role,
                'created_at'      => $h->created_at,
            ];
        });

        // Legacy timeline (fallback from timestamps)
        $timeline = [];
        $timeline[] = ['status' => 'placed',    'at' => $order->created_at];
        if ($order->paid_at)      $timeline[] = ['status' => 'paid',      'at' => $order->paid_at];
        if ($order->packed_at)    $timeline[] = ['status' => 'packed',    'at' => $order->packed_at];
        if ($order->shipped_at)   $timeline[] = ['status' => 'shipped',   'at' => $order->shipped_at];
        if ($order->delivered_at) $timeline[] = ['status' => 'delivered', 'at' => $order->delivered_at];
        if ($order->cancelled_at) $timeline[] = ['status' => 'cancelled', 'at' => $order->cancelled_at];

        // Return requests
        $returnRequests = $order->returnRequests->map(function ($ret) {
            return [
                'id'                      => $ret->id,
                'return_number'           => $ret->return_number,
                'status'                  => $ret->status,
                'reason'                  => $ret->reason,
                'refund_amount_requested' => $ret->refund_amount_requested,
                'refund_amount_approved'  => $ret->refund_amount_approved,
                'requested_at'            => $ret->requested_at,
                'approved_at'             => $ret->approved_at,
                'rejected_at'             => $ret->rejected_at,
                'returned_at'             => $ret->returned_at,
                'items'                   => $ret->items->map(function ($item) {
                    return [
                        'shop_order_item_id' => $item->shop_order_item_id,
                        'quantity'           => $item->quantity,
                        'reason'             => $item->reason,
                        'unit_price'         => $item->unit_price,
                        'line_total'         => $item->line_total,
                    ];
                }),
            ];
        });

        // Refunds
        $refunds = $order->refunds->map(function ($ref) {
            return [
                'id'              => $ref->id,
                'refund_number'   => $ref->refund_number,
                'amount'          => $ref->amount,
                'status'          => $ref->status,
                'refund_method'   => $ref->refund_method,
                'processed_at'    => $ref->processed_at,
                'created_at'      => $ref->created_at,
            ];
        });

        return response()->json([
            'order' => [
                'id'                => $order->id,
                'order_number'      => $order->order_number,
                'order_status'      => $order->order_status,
                'payment_status'    => $order->payment_status,
                'payment_method'    => $order->payment_method,
                'payment_reference' => $order->payment_reference,
                'subtotal'          => $order->subtotal,
                'shipping_charge'   => $order->shipping_charge,
                'discount_amount'   => $order->discount_amount,
                'coupon_code'       => $order->coupon_code,
                'tax_amount'        => $order->tax_amount,
                'total_amount'      => $order->total_amount,
                'return_status'     => $order->return_status,
                'refund_status'     => $order->refund_status,
                'customer_note'     => $order->customer_note,
                'shipping' => [
                    'name'           => $order->shipping_name,
                    'phone'          => $order->shipping_phone,
                    'address_line_1' => $order->shipping_address_line_1,
                    'address_line_2' => $order->shipping_address_line_2,
                    'city'           => $order->shipping_city,
                    'state'          => $order->shipping_state,
                    'pincode'        => $order->shipping_pincode,
                    'country'        => $order->shipping_country,
                ],
                'items'             => $items,
                'timeline'          => $timeline,
                'status_histories'  => $statusHistories,
                'return_requests'   => $returnRequests,
                'refunds'           => $refunds,
                'created_at'        => $order->created_at,
            ],
        ], 200);
    }

    /**
     * POST /api/shop/my-orders/{orderId}/cancel
     * Cancel order if status is paid or processing. Restores stock.
     */
    public function cancel(Request $request, $orderId)
    {
        $user = $request->user();

        $order = ShopOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('items')
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $cancellableStatuses = [ShopOrder::STATUS_PAID, ShopOrder::STATUS_PROCESSING];

        if (!in_array($order->order_status, $cancellableStatuses)) {
            return response()->json([
                'message' => "Order cannot be cancelled. Current status: {$order->order_status}.",
            ], 422);
        }

        $oldStatus = $order->order_status;

        // Cancel and restore stock
        $order->update([
            'order_status' => ShopOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);

        // Restore stock for each item
        foreach ($order->items as $item) {
            if (!$item->product_id) continue;

            $product = Product::find($item->product_id);
            if (!$product) continue;

            $quantityBefore = $product->stock_quantity;
            $quantityAfter = $quantityBefore + $item->quantity;

            $product->update(['stock_quantity' => $quantityAfter]);

            ProductInventoryLog::create([
                'product_id'       => $product->id,
                'change_type'      => ProductInventoryLog::TYPE_ORDER_CANCEL_RESTORE,
                'quantity_before'  => $quantityBefore,
                'quantity_after'   => $quantityAfter,
                'quantity_changed' => $item->quantity,
                'reason'           => "Stock restored — order {$order->order_number} cancelled by customer.",
                'admin_user_id'    => null,
            ]);
        }

        // Create status history
        ShopOrderStatusHistory::create([
            'shop_order_id'   => $order->id,
            'old_status'      => $oldStatus,
            'new_status'      => ShopOrder::STATUS_CANCELLED,
            'note'            => 'Order cancelled by customer.',
            'changed_by'      => $user->id,
            'changed_by_role' => 'customer',
        ]);

        // Phase 11: Notify about customer cancellation
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'shop_order',
                'entity_id'   => $order->id,
                'data'        => ['order_number' => $order->order_number],
            ];

            $notificationService->notifyUser(
                $user->id,
                'Order cancelled',
                "Your order {$order->order_number} has been cancelled.",
                \App\Models\Notification::TYPE_SHOP_ORDER_CANCELLED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=my-orders'])
            );

            $notificationService->notifyAdmins(
                'Shop order cancelled by customer',
                "Order {$order->order_number} has been cancelled by the customer.",
                \App\Models\Notification::TYPE_SHOP_ORDER_CANCELLED,
                array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=shop-orders'])
            );
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => 'Order cancelled successfully.',
            'order'   => [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'order_status'   => $order->order_status,
                'payment_status' => $order->payment_status,
                'cancelled_at'   => $order->cancelled_at,
            ],
        ], 200);
    }

    /**
     * POST /api/shop/my-orders/{orderId}/request-return
     * Request a return for a delivered order.
     */
    public function requestReturn(Request $request, $orderId)
    {
        $user = $request->user();

        $order = ShopOrder::where('id', $orderId)
            ->where('user_id', $user->id)
            ->with('items')
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Must be delivered
        if ($order->order_status !== ShopOrder::STATUS_DELIVERED) {
            return response()->json(['message' => 'Returns can only be requested for delivered orders.'], 422);
        }

        // Within 7 days of delivery
        if ($order->delivered_at && now()->diffInDays($order->delivered_at) > 7) {
            return response()->json(['message' => 'Return window has expired. Returns must be requested within 7 days of delivery.'], 422);
        }

        // No active return request
        $activeReturn = ShopReturnRequest::where('shop_order_id', $order->id)
            ->whereIn('status', [
                ShopReturnRequest::STATUS_REQUESTED,
                ShopReturnRequest::STATUS_APPROVED,
            ])
            ->exists();

        if ($activeReturn) {
            return response()->json(['message' => 'An active return request already exists for this order.'], 422);
        }

        $validated = $request->validate([
            'reason'         => 'required|string|max:2000',
            'customer_note'  => 'nullable|string|max:2000',
            'items'          => 'required|array|min:1',
            'items.*.shop_order_item_id' => 'required|integer|exists:shop_order_items,id',
            'items.*.quantity'           => 'required|integer|min:1',
            'items.*.reason'             => 'nullable|string|max:1000',
        ]);

        // Validate items belong to this order and quantities
        $refundAmountRequested = 0;
        $returnItemsData = [];

        foreach ($validated['items'] as $itemData) {
            $orderItem = $order->items->firstWhere('id', $itemData['shop_order_item_id']);

            if (!$orderItem) {
                return response()->json([
                    'message' => "Order item #{$itemData['shop_order_item_id']} does not belong to this order.",
                ], 422);
            }

            if ($itemData['quantity'] > $orderItem->quantity) {
                return response()->json([
                    'message' => "Return quantity for \"{$orderItem->product_name}\" cannot exceed ordered quantity ({$orderItem->quantity}).",
                ], 422);
            }

            $lineTotal = (float) $orderItem->unit_price * $itemData['quantity'];
            $refundAmountRequested += $lineTotal;

            $returnItemsData[] = [
                'shop_order_item_id' => $orderItem->id,
                'product_id'         => $orderItem->product_id,
                'quantity'           => $itemData['quantity'],
                'reason'             => $itemData['reason'] ?? null,
                'unit_price'         => $orderItem->unit_price,
                'line_total'         => $lineTotal,
            ];
        }

        // Create return request
        $returnRequest = ShopReturnRequest::create([
            'return_number'           => ShopReturnRequest::generateReturnNumber(),
            'shop_order_id'           => $order->id,
            'user_id'                 => $user->id,
            'status'                  => ShopReturnRequest::STATUS_REQUESTED,
            'reason'                  => $validated['reason'],
            'customer_note'           => $validated['customer_note'] ?? null,
            'refund_amount_requested' => round($refundAmountRequested, 2),
            'requested_at'            => now(),
        ]);

        // Create return items
        foreach ($returnItemsData as $rid) {
            ShopReturnItem::create(array_merge($rid, [
                'shop_return_request_id' => $returnRequest->id,
            ]));
        }

        // Update order statuses
        $order->update([
            'return_status' => ShopOrder::RETURN_REQUESTED,
            'refund_status' => ShopOrder::REFUND_PENDING,
        ]);

        $returnRequest->load('items');

        // Phase 11: Notify about return request
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'shop_return',
                'entity_id'   => $returnRequest->id,
                'data'        => ['return_number' => $returnRequest->return_number, 'order_number' => $order->order_number],
            ];

            $notificationService->notifyUser(
                $user->id,
                'Return request submitted',
                "Your return request {$returnRequest->return_number} for order {$order->order_number} has been submitted.",
                \App\Models\Notification::TYPE_SHOP_RETURN_REQUESTED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=my-returns'])
            );

            $notificationService->notifyAdmins(
                'New return request',
                "Return request {$returnRequest->return_number} submitted for order {$order->order_number}.",
                \App\Models\Notification::TYPE_SHOP_RETURN_REQUESTED,
                array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=shop-returns'])
            );
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message'        => 'Return request submitted successfully.',
            'return_request' => [
                'id'                      => $returnRequest->id,
                'return_number'           => $returnRequest->return_number,
                'status'                  => $returnRequest->status,
                'reason'                  => $returnRequest->reason,
                'customer_note'           => $returnRequest->customer_note,
                'refund_amount_requested' => $returnRequest->refund_amount_requested,
                'requested_at'            => $returnRequest->requested_at,
                'items'                   => $returnRequest->items->map(function ($item) {
                    return [
                        'shop_order_item_id' => $item->shop_order_item_id,
                        'quantity'           => $item->quantity,
                        'reason'             => $item->reason,
                        'unit_price'         => $item->unit_price,
                        'line_total'         => $item->line_total,
                    ];
                }),
            ],
        ], 201);
    }

    /**
     * GET /api/shop/my-returns
     * List user's return requests.
     */
    public function myReturns(Request $request)
    {
        $user = $request->user();

        $perPage = min((int) $request->input('per_page', 15), 50);

        $returns = ShopReturnRequest::where('user_id', $user->id)
            ->with('shopOrder:id,order_number')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = collect($returns->items())->map(function ($ret) {
            return [
                'id'                      => $ret->id,
                'return_number'           => $ret->return_number,
                'order_number'            => $ret->shopOrder?->order_number,
                'status'                  => $ret->status,
                'refund_amount_requested' => $ret->refund_amount_requested,
                'refund_amount_approved'  => $ret->refund_amount_approved,
                'requested_at'            => $ret->requested_at,
                'created_at'              => $ret->created_at,
            ];
        });

        return response()->json([
            'returns' => $items,
            'pagination' => [
                'current_page' => $returns->currentPage(),
                'last_page'    => $returns->lastPage(),
                'per_page'     => $returns->perPage(),
                'total'        => $returns->total(),
            ],
        ], 200);
    }

    /**
     * GET /api/shop/my-returns/{returnId}
     * Show return request detail for the user.
     */
    public function showReturn(Request $request, $returnId)
    {
        $user = $request->user();

        $returnRequest = ShopReturnRequest::where('id', $returnId)
            ->where('user_id', $user->id)
            ->with(['shopOrder:id,order_number', 'items.orderItem', 'refunds'])
            ->first();

        if (!$returnRequest) {
            return response()->json(['message' => 'Return request not found.'], 404);
        }

        return response()->json([
            'return_request' => [
                'id'                      => $returnRequest->id,
                'return_number'           => $returnRequest->return_number,
                'order_number'            => $returnRequest->shopOrder?->order_number,
                'status'                  => $returnRequest->status,
                'reason'                  => $returnRequest->reason,
                'customer_note'           => $returnRequest->customer_note,
                'admin_note'              => $returnRequest->admin_note,
                'refund_amount_requested' => $returnRequest->refund_amount_requested,
                'refund_amount_approved'  => $returnRequest->refund_amount_approved,
                'items'                   => $returnRequest->items->map(function ($item) {
                    return [
                        'shop_order_item_id' => $item->shop_order_item_id,
                        'product_name'       => $item->orderItem?->product_name,
                        'quantity'           => $item->quantity,
                        'reason'             => $item->reason,
                        'unit_price'         => $item->unit_price,
                        'line_total'         => $item->line_total,
                    ];
                }),
                'refunds'                 => $returnRequest->refunds->map(function ($ref) {
                    return [
                        'refund_number' => $ref->refund_number,
                        'amount'        => $ref->amount,
                        'status'        => $ref->status,
                        'created_at'    => $ref->created_at,
                    ];
                }),
                'requested_at'            => $returnRequest->requested_at,
                'approved_at'             => $returnRequest->approved_at,
                'rejected_at'             => $returnRequest->rejected_at,
                'returned_at'             => $returnRequest->returned_at,
                'created_at'              => $returnRequest->created_at,
            ],
        ], 200);
    }

    /**
     * POST /api/shop/products/{productId}/reviews
     * Submit a product review.
     */
    public function submitReview(Request $request, $productId)
    {
        $user = $request->user();

        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $validated = $request->validate([
            'shop_order_id'      => 'required|integer|exists:shop_orders,id',
            'shop_order_item_id' => 'nullable|integer|exists:shop_order_items,id',
            'rating'             => 'required|integer|min:1|max:5',
            'comment'            => 'nullable|string|max:2000',
        ]);

        // User must own the order
        $order = ShopOrder::where('id', $validated['shop_order_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // Order must be delivered
        if ($order->order_status !== ShopOrder::STATUS_DELIVERED) {
            return response()->json(['message' => 'You can only review products from delivered orders.'], 422);
        }

        // User must have purchased this product in this order
        $orderItem = ShopOrderItem::where('shop_order_id', $order->id)
            ->where('product_id', $productId)
            ->first();

        if (!$orderItem) {
            return response()->json(['message' => 'You did not purchase this product in the specified order.'], 422);
        }

        // Check for existing review (one per user+product+order)
        $existingReview = ProductReview::where('user_id', $user->id)
            ->where('product_id', $productId)
            ->where('shop_order_id', $order->id)
            ->first();

        if ($existingReview) {
            return response()->json(['message' => 'You have already reviewed this product for this order.'], 422);
        }

        $review = ProductReview::create([
            'product_id'        => $productId,
            'user_id'           => $user->id,
            'shop_order_id'     => $order->id,
            'shop_order_item_id'=> $validated['shop_order_item_id'] ?? $orderItem->id,
            'rating'            => $validated['rating'],
            'comment'           => $validated['comment'] ?? null,
            'is_visible'        => true,
            'verified_purchase' => true,
        ]);

        // Phase 11: Notify admins about new review
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notificationService->notifyAdmins(
                'New product review',
                "A new {$validated['rating']}-star review has been submitted for \"{$product->name}\".",
                \App\Models\Notification::TYPE_SHOP_REVIEW_SUBMITTED,
                [
                    'entity_type' => 'product_review',
                    'entity_id'   => $review->id,
                    'action_url'  => '/admin/control-panel?section=product-reviews',
                    'data'        => ['product_name' => $product->name, 'rating' => $validated['rating']],
                ]
            );
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => 'Review submitted successfully.',
            'review'  => [
                'id'                => $review->id,
                'product_id'        => $review->product_id,
                'rating'            => $review->rating,
                'comment'           => $review->comment,
                'verified_purchase' => $review->verified_purchase,
                'created_at'        => $review->created_at,
            ],
        ], 201);
    }

    /**
     * GET /api/shop/products/{productId}/reviews
     * List visible reviews for a product (public).
     */
    public function productReviews(Request $request, $productId)
    {
        $product = Product::find($productId);
        if (!$product) {
            return response()->json(['message' => 'Product not found.'], 404);
        }

        $perPage = min((int) $request->input('per_page', 15), 50);

        $reviews = ProductReview::where('product_id', $productId)
            ->where('is_visible', true)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $items = collect($reviews->items())->map(function ($review) {
            return [
                'id'                => $review->id,
                'rating'            => $review->rating,
                'comment'           => $review->comment,
                'customer_name'     => $review->user?->name,
                'verified_purchase' => $review->verified_purchase,
                'created_at'        => $review->created_at,
            ];
        });

        return response()->json([
            'reviews' => $items,
            'average_rating' => $product->average_rating,
            'total_reviews'  => $product->total_reviews,
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'per_page'     => $reviews->perPage(),
                'total'        => $reviews->total(),
            ],
        ], 200);
    }
}
