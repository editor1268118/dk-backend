<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopOrder;
use App\Models\ShopOrderStatusHistory;
use App\Models\Product;
use App\Models\ProductInventoryLog;
use App\Models\AdminActionLog;
use Illuminate\Http\Request;

class AdminShopOrderController extends Controller
{
    /**
     * GET /api/admin/shop/orders
     * List all shop orders with search, filtering, and pagination.
     */
    public function index(Request $request)
    {
        $query = ShopOrder::with(['user:id,name,email,phone']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        // Filters
        if ($request->filled('order_status')) {
            $query->where('order_status', $request->order_status);
        }
        if ($request->filled('payment_status')) {
            $query->where('payment_status', $request->payment_status);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSorts = ['id', 'order_number', 'total_amount', 'order_status', 'payment_status', 'created_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
        }

        // Pagination
        $perPage = min((int) $request->input('per_page', 20), 100);
        $orders = $query->paginate($perPage);

        $items = collect($orders->items())->map(function ($order) {
            return [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'customer'       => [
                    'id'    => $order->user?->id,
                    'name'  => $order->customer_name ?? $order->user?->name,
                    'email' => $order->customer_email ?? $order->user?->email,
                    'phone' => $order->customer_phone ?? $order->user?->phone,
                ],
                'total_amount'   => $order->total_amount,
                'payment_status' => $order->payment_status,
                'order_status'   => $order->order_status,
                'return_status'  => $order->return_status,
                'refund_status'  => $order->refund_status,
                'coupon_code'    => $order->coupon_code,
                'item_count'     => $order->items()->count(),
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
     * GET /api/admin/shop/orders/{orderId}
     * Show full order detail with status histories, returns, and refunds.
     */
    public function show(Request $request, $orderId)
    {
        $order = ShopOrder::with([
            'user:id,name,email,phone',
            'items',
            'statusHistories',
            'returnRequests.items',
            'refunds',
        ])->find($orderId);

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

        // Status histories
        $statusHistories = $order->statusHistories->map(function ($h) {
            return [
                'id'              => $h->id,
                'old_status'      => $h->old_status,
                'new_status'      => $h->new_status,
                'note'            => $h->note,
                'changed_by'      => $h->changed_by,
                'changed_by_role' => $h->changed_by_role,
                'created_at'      => $h->created_at,
            ];
        });

        // Legacy timeline
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
            ];
        });

        return response()->json([
            'order' => [
                'id'                => $order->id,
                'order_number'      => $order->order_number,
                'customer'          => [
                    'id'    => $order->user?->id,
                    'name'  => $order->customer_name ?? $order->user?->name,
                    'email' => $order->customer_email ?? $order->user?->email,
                    'phone' => $order->customer_phone ?? $order->user?->phone,
                ],
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
                'subtotal'          => $order->subtotal,
                'shipping_charge'   => $order->shipping_charge,
                'discount_amount'   => $order->discount_amount,
                'coupon_code'       => $order->coupon_code,
                'tax_amount'        => $order->tax_amount,
                'total_amount'      => $order->total_amount,
                'payment_status'    => $order->payment_status,
                'payment_method'    => $order->payment_method,
                'payment_reference' => $order->payment_reference,
                'order_status'      => $order->order_status,
                'return_status'     => $order->return_status,
                'refund_status'     => $order->refund_status,
                'admin_note'        => $order->admin_note,
                'customer_note'     => $order->customer_note,
                'items'             => $items,
                'timeline'          => $timeline,
                'status_histories'  => $statusHistories,
                'return_requests'   => $returnRequests,
                'refunds'           => $refunds,
                'created_at'        => $order->created_at,
                'updated_at'        => $order->updated_at,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/orders/{orderId}/update-status
     * Update order status with appropriate timestamp and status history.
     */
    public function updateStatus(Request $request, $orderId)
    {
        $order = ShopOrder::with('items')->find($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'order_status' => 'required|string|in:' . implode(',', ShopOrder::ORDER_STATUSES),
            'admin_note'   => 'nullable|string|max:2000',
        ]);

        $oldStatus = $order->order_status;
        $newStatus = $validated['order_status'];

        // Set relevant timestamp
        $timestampUpdates = [];
        switch ($newStatus) {
            case ShopOrder::STATUS_PACKED:
                $timestampUpdates['packed_at'] = now();
                break;
            case ShopOrder::STATUS_SHIPPED:
                $timestampUpdates['shipped_at'] = now();
                break;
            case ShopOrder::STATUS_DELIVERED:
                $timestampUpdates['delivered_at'] = now();
                break;
            case ShopOrder::STATUS_CANCELLED:
                $timestampUpdates['cancelled_at'] = now();
                break;
        }

        $order->update(array_merge(
            ['order_status' => $newStatus],
            $timestampUpdates,
            $validated['admin_note'] ? ['admin_note' => $validated['admin_note']] : []
        ));

        // If admin cancels an order that hasn't been shipped/delivered, restore stock
        if ($newStatus === ShopOrder::STATUS_CANCELLED && in_array($oldStatus, [
            ShopOrder::STATUS_PENDING,
            ShopOrder::STATUS_PAID,
            ShopOrder::STATUS_PROCESSING,
            ShopOrder::STATUS_PACKED,
        ])) {
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
                    'reason'           => "Stock restored — order {$order->order_number} cancelled by admin.",
                    'admin_user_id'    => $request->user()->id,
                ]);
            }
        }

        // Create status history
        ShopOrderStatusHistory::create([
            'shop_order_id'   => $order->id,
            'old_status'      => $oldStatus,
            'new_status'      => $newStatus,
            'note'            => $validated['admin_note'] ?? null,
            'changed_by'      => $request->user()->id,
            'changed_by_role' => 'admin',
        ]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'shop_order_status_updated',
            'shop_order',
            $order->id,
            "Order {$order->order_number} status changed from '{$oldStatus}' to '{$newStatus}'.",
            null,
            ['order_status' => $oldStatus],
            ['order_status' => $newStatus]
        );

        // Phase 11: Notify customer about status change
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'shop_order',
                'entity_id'   => $order->id,
                'action_url'  => '/dashboard?tab=my-orders',
                'data'        => ['order_number' => $order->order_number, 'old_status' => $oldStatus, 'new_status' => $newStatus],
            ];

            if ($newStatus === ShopOrder::STATUS_DELIVERED) {
                $notificationService->notifyUser(
                    $order->user_id,
                    'Order delivered',
                    "Your order {$order->order_number} has been delivered. You can now review your products.",
                    \App\Models\Notification::TYPE_SHOP_ORDER_DELIVERED,
                    $notifOptions
                );
            } elseif ($newStatus === ShopOrder::STATUS_CANCELLED) {
                // Notify customer
                $notificationService->notifyUser(
                    $order->user_id,
                    'Shop order cancelled',
                    "Your order {$order->order_number} has been cancelled by admin.",
                    \App\Models\Notification::TYPE_SHOP_ORDER_CANCELLED,
                    $notifOptions
                );

                // Notify admins
                $notificationService->notifyAdmins(
                    'Shop order cancelled',
                    "Order {$order->order_number} has been cancelled by admin.",
                    \App\Models\Notification::TYPE_SHOP_ORDER_CANCELLED,
                    array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=shop-orders'])
                );
            } else {
                $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
                $notificationService->notifyUser(
                    $order->user_id,
                    'Order status updated',
                    "Your order {$order->order_number} status has been updated to {$statusLabel}.",
                    \App\Models\Notification::TYPE_SHOP_ORDER_STATUS_UPDATED,
                    $notifOptions
                );
            }
        } catch (\Exception $e) {
            // Never fail core action
        }

        return response()->json([
            'message' => "Order status updated to '{$newStatus}'.",
            'order'   => [
                'id'             => $order->id,
                'order_number'   => $order->order_number,
                'order_status'   => $order->order_status,
                'payment_status' => $order->payment_status,
                'admin_note'     => $order->admin_note,
            ],
        ], 200);
    }

    /**
     * POST /api/admin/shop/orders/{orderId}/add-note
     * Add or update admin note.
     */
    public function addNote(Request $request, $orderId)
    {
        $order = ShopOrder::find($orderId);

        if (!$order) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $validated = $request->validate([
            'admin_note' => 'required|string|max:2000',
        ]);

        $oldNote = $order->admin_note;
        $order->update(['admin_note' => $validated['admin_note']]);

        // Log admin action
        AdminActionLog::record(
            $request->user()->id,
            'shop_order_note_added',
            'shop_order',
            $order->id,
            "Admin note updated for order {$order->order_number}.",
            null,
            ['admin_note' => $oldNote],
            ['admin_note' => $validated['admin_note']]
        );

        return response()->json([
            'message' => 'Admin note updated.',
            'order'   => [
                'id'           => $order->id,
                'order_number' => $order->order_number,
                'admin_note'   => $order->admin_note,
            ],
        ], 200);
    }
}
