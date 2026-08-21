<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\ShopOrderItem;
use App\Models\ShopOrderStatusHistory;
use App\Models\ProductInventoryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopCheckoutController extends Controller
{
    /**
     * POST /api/shop/checkout
     * Process checkout: validate stock, revalidate coupon, create order, deduct stock, mock payment.
     */
    public function checkout(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'shipping_name'           => 'required|string|max:255',
            'shipping_phone'          => 'required|string|max:20',
            'shipping_address_line_1' => 'required|string|max:500',
            'shipping_address_line_2' => 'nullable|string|max:500',
            'shipping_city'           => 'required|string|max:100',
            'shipping_state'          => 'required|string|max:100',
            'shipping_pincode'        => 'required|string|max:20',
            'shipping_country'        => 'required|string|max:100',
            'payment_method'          => 'required|string|in:mock',
            'customer_note'           => 'nullable|string|max:1000',
        ]);

        // Get active cart with items
        $cart = Cart::where('user_id', $user->id)
            ->where('status', Cart::STATUS_ACTIVE)
            ->with('items.product')
            ->first();

        if (!$cart) {
            return response()->json(['message' => 'No active cart found.'], 422);
        }

        if ($cart->items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 422);
        }

        // Use a DB transaction with product locking
        try {
            $order = DB::transaction(function () use ($user, $cart, $validated) {

                $subtotal = 0;
                $orderItems = [];

                foreach ($cart->items as $cartItem) {
                    // Lock the product row to prevent overselling
                    $product = Product::where('id', $cartItem->product_id)->lockForUpdate()->first();

                    if (!$product) {
                        throw new \Exception("Product \"{$cartItem->product_id}\" no longer exists.");
                    }

                    if (!$product->is_active || $product->status !== Product::STATUS_PUBLISHED) {
                        throw new \Exception("Product \"{$product->name}\" is no longer available.");
                    }

                    if ($product->stock_quantity < $cartItem->quantity) {
                        throw new \Exception("Insufficient stock for \"{$product->name}\". Available: {$product->stock_quantity}, requested: {$cartItem->quantity}.");
                    }

                    // Use current effective price at checkout time
                    $effectivePrice = $product->sale_price !== null ? $product->sale_price : $product->price;
                    $lineTotal = $effectivePrice * $cartItem->quantity;
                    $subtotal += $lineTotal;

                    $orderItems[] = [
                        'product'        => $product,
                        'product_name'   => $product->name,
                        'product_sku'    => $product->sku,
                        'product_image'  => $product->main_image,
                        'quantity'       => $cartItem->quantity,
                        'unit_price'     => $effectivePrice,
                        'line_total'     => $lineTotal,
                    ];
                }

                // ── Revalidate and recalculate coupon ──
                $couponId = null;
                $couponCode = null;
                $discountAmount = 0;
                $coupon = null;

                if ($cart->coupon_id) {
                    $coupon = Coupon::find($cart->coupon_id);

                    if ($coupon && $coupon->isCurrentlyValid()
                        && !$coupon->isUsageLimitReached()
                        && !$coupon->isPerUserLimitReached($user->id)
                        && $subtotal >= (float) $coupon->min_order_amount
                    ) {
                        $couponId = $coupon->id;
                        $couponCode = $coupon->code;
                        $discountAmount = $coupon->calculateDiscount($subtotal);
                    }
                    // If coupon is no longer valid, silently ignore it
                }

                $totalAmount = max($subtotal - $discountAmount, 0);

                // Generate order number
                $orderNumber = ShopOrder::generateOrderNumber();

                // Create the order
                $order = ShopOrder::create([
                    'order_number'            => $orderNumber,
                    'user_id'                 => $user->id,
                    'cart_id'                 => $cart->id,
                    'customer_name'           => $user->name,
                    'customer_email'          => $user->email,
                    'customer_phone'          => $user->phone,
                    'shipping_name'           => $validated['shipping_name'],
                    'shipping_phone'          => $validated['shipping_phone'],
                    'shipping_address_line_1' => $validated['shipping_address_line_1'],
                    'shipping_address_line_2' => $validated['shipping_address_line_2'] ?? null,
                    'shipping_city'           => $validated['shipping_city'],
                    'shipping_state'          => $validated['shipping_state'],
                    'shipping_pincode'        => $validated['shipping_pincode'],
                    'shipping_country'        => $validated['shipping_country'],
                    'subtotal'                => $subtotal,
                    'shipping_charge'         => 0,
                    'discount_amount'         => $discountAmount,
                    'tax_amount'              => 0,
                    'total_amount'            => $totalAmount,
                    'coupon_id'               => $couponId,
                    'coupon_code'             => $couponCode,
                    'payment_method'          => 'mock',
                    'payment_reference'       => 'MOCK-' . Str::uuid(),
                    'payment_status'          => ShopOrder::PAYMENT_PAID,
                    'order_status'            => ShopOrder::STATUS_PAID,
                    'customer_note'           => $validated['customer_note'] ?? null,
                    'paid_at'                 => now(),
                ]);

                // Create order items + deduct stock + create inventory logs
                foreach ($orderItems as $itemData) {
                    ShopOrderItem::create([
                        'shop_order_id' => $order->id,
                        'product_id'    => $itemData['product']->id,
                        'product_name'  => $itemData['product_name'],
                        'product_sku'   => $itemData['product_sku'],
                        'product_image' => $itemData['product_image'],
                        'quantity'      => $itemData['quantity'],
                        'unit_price'    => $itemData['unit_price'],
                        'line_total'    => $itemData['line_total'],
                    ]);

                    $product = $itemData['product'];
                    $quantityBefore = $product->stock_quantity;
                    $quantityAfter = $quantityBefore - $itemData['quantity'];

                    $product->update(['stock_quantity' => $quantityAfter]);

                    ProductInventoryLog::create([
                        'product_id'       => $product->id,
                        'change_type'      => ProductInventoryLog::TYPE_ORDER_DEDUCTION,
                        'quantity_before'  => $quantityBefore,
                        'quantity_after'   => $quantityAfter,
                        'quantity_changed' => -$itemData['quantity'],
                        'reason'           => "Stock deducted for order {$order->order_number}.",
                        'admin_user_id'    => null,
                    ]);
                }

                // ── Create coupon usage record ──
                if ($coupon && $couponId) {
                    CouponUsage::create([
                        'coupon_id'       => $couponId,
                        'user_id'         => $user->id,
                        'shop_order_id'   => $order->id,
                        'discount_amount' => $discountAmount,
                        'used_at'         => now(),
                    ]);

                    // Increment used_count
                    $coupon->increment('used_count');
                }

                // ── Create status history for paid ──
                ShopOrderStatusHistory::create([
                    'shop_order_id'  => $order->id,
                    'old_status'     => null,
                    'new_status'     => ShopOrder::STATUS_PAID,
                    'note'           => 'Mock payment successful. Order placed.',
                    'changed_by'     => $user->id,
                    'changed_by_role'=> 'customer',
                ]);

                // Mark cart as converted
                $cart->update(['status' => Cart::STATUS_CONVERTED]);

                return $order;
            });

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Load order with items
        $order->load('items');

        // Phase 11: Notify about order placed + low stock
        try {
            $notificationService = app(\App\Services\NotificationService::class);
            $notifOptions = [
                'entity_type' => 'shop_order',
                'entity_id'   => $order->id,
                'data'        => ['order_number' => $order->order_number],
            ];

            // Notify customer
            $notificationService->notifyUser(
                $user->id,
                'Order placed successfully',
                "Your order {$order->order_number} has been placed successfully.",
                \App\Models\Notification::TYPE_SHOP_ORDER_PLACED,
                array_merge($notifOptions, ['action_url' => '/dashboard?tab=my-orders'])
            );

            // Notify admins
            $notificationService->notifyAdmins(
                'New shop order received',
                "A new shop order {$order->order_number} has been placed.",
                \App\Models\Notification::TYPE_SHOP_ORDER_PLACED,
                array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=shop-orders'])
            );

            // Low stock alerts for products that dropped below threshold
            foreach ($order->items as $item) {
                if (!$item->product_id) continue;
                $product = Product::find($item->product_id);
                if ($product && $product->stock_quantity <= $product->low_stock_threshold) {
                    $notificationService->notifyAdmins(
                        'Low stock alert',
                        "Product \"{$product->name}\" (SKU: {$product->sku}) stock is low ({$product->stock_quantity} remaining).",
                        \App\Models\Notification::TYPE_SHOP_PRODUCT_LOW_STOCK,
                        [
                            'priority'    => \App\Models\Notification::PRIORITY_HIGH,
                            'entity_type' => 'product',
                            'entity_id'   => $product->id,
                            'action_url'  => '/admin/control-panel?section=inventory',
                            'data'        => ['product_name' => $product->name, 'sku' => $product->sku, 'stock' => $product->stock_quantity],
                        ]
                    );
                }
            }
        } catch (\Exception $e) {
            // Never fail core action
        }

        // Build item response
        $orderItemsResponse = $order->items->map(function ($item) {
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

        return response()->json([
            'message' => 'Order placed successfully.',
            'order'   => [
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
                'shipping'          => [
                    'name'           => $order->shipping_name,
                    'phone'          => $order->shipping_phone,
                    'address_line_1' => $order->shipping_address_line_1,
                    'address_line_2' => $order->shipping_address_line_2,
                    'city'           => $order->shipping_city,
                    'state'          => $order->shipping_state,
                    'pincode'        => $order->shipping_pincode,
                    'country'        => $order->shipping_country,
                ],
                'items'             => $orderItemsResponse,
                'paid_at'           => $order->paid_at,
                'created_at'        => $order->created_at,
            ],
        ], 201);
    }
}
