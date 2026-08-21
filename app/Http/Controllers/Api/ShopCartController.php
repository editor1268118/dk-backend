<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;

class ShopCartController extends Controller
{
    /**
     * GET /api/shop/cart
     * Return the active cart for the authenticated user (create if not exists).
     */
    public function getCart(Request $request)
    {
        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)
            ->where('status', Cart::STATUS_ACTIVE)
            ->first();

        if (!$cart) {
            $cart = Cart::create([
                'user_id' => $user->id,
                'status'  => Cart::STATUS_ACTIVE,
            ]);
        }

        $cart->load(['items.product' => function ($q) {
            $q->select('id', 'name', 'slug', 'price', 'sale_price', 'stock_quantity', 'main_image', 'is_active', 'status');
        }]);

        $items = $cart->items->map(function ($item) {
            return [
                'id'                       => $item->id,
                'product_id'               => $item->product_id,
                'product'                  => $item->product ? [
                    'id'              => $item->product->id,
                    'name'            => $item->product->name,
                    'slug'            => $item->product->slug,
                    'price'           => $item->product->price,
                    'sale_price'      => $item->product->sale_price,
                    'stock_quantity'  => $item->product->stock_quantity,
                    'main_image_url'  => $item->product->main_image_url,
                    'is_active'       => $item->product->is_active,
                    'status'          => $item->product->status,
                ] : null,
                'quantity'                 => $item->quantity,
                'price_snapshot'           => $item->price_snapshot,
                'sale_price_snapshot'      => $item->sale_price_snapshot,
                'effective_price_snapshot' => $item->effective_price_snapshot,
                'line_total'               => $item->line_total,
            ];
        });

        // Build applied coupon info
        $appliedCoupon = null;
        if ($cart->coupon_id && $cart->coupon_code) {
            $appliedCoupon = [
                'coupon_id'   => $cart->coupon_id,
                'coupon_code' => $cart->coupon_code,
            ];
        }

        return response()->json([
            'cart' => [
                'id'                  => $cart->id,
                'items'               => $items,
                'subtotal'            => $cart->subtotal,
                'discount_amount'     => number_format((float) ($cart->discount_amount ?? 0), 2, '.', ''),
                'total_after_discount'=> $cart->total_after_discount,
                'total_quantity'      => $cart->total_quantity,
                'applied_coupon'      => $appliedCoupon,
            ],
        ], 200);
    }

    /**
     * POST /api/shop/cart/items
     * Add item to cart (or increase quantity if already in cart).
     */
    public function addItem(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        $product = Product::find($validated['product_id']);

        if (!$product->is_active || $product->status !== Product::STATUS_PUBLISHED) {
            return response()->json(['message' => 'This product is not currently available.'], 422);
        }

        if ($product->stock_quantity <= 0) {
            return response()->json(['message' => 'This product is out of stock.'], 422);
        }

        // Get or create active cart
        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => Cart::STATUS_ACTIVE],
            ['user_id' => $user->id, 'status' => Cart::STATUS_ACTIVE]
        );

        // Check if product already in cart
        $existingItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->first();

        $requestedQty = $validated['quantity'];

        if ($existingItem) {
            $requestedQty += $existingItem->quantity;
        }

        // Check stock
        if ($requestedQty > $product->stock_quantity) {
            return response()->json([
                'message' => "Cannot add that many. Only {$product->stock_quantity} available for \"{$product->name}\".",
            ], 422);
        }

        // Price snapshots
        $priceSnapshot = $product->price;
        $salePriceSnapshot = $product->sale_price;
        $effectivePrice = $product->sale_price !== null ? $product->sale_price : $product->price;
        $lineTotal = $effectivePrice * $requestedQty;

        if ($existingItem) {
            $existingItem->update([
                'quantity'                 => $requestedQty,
                'price_snapshot'           => $priceSnapshot,
                'sale_price_snapshot'      => $salePriceSnapshot,
                'effective_price_snapshot' => $effectivePrice,
                'line_total'               => $lineTotal,
            ]);
        } else {
            CartItem::create([
                'cart_id'                  => $cart->id,
                'product_id'               => $product->id,
                'quantity'                 => $requestedQty,
                'price_snapshot'           => $priceSnapshot,
                'sale_price_snapshot'      => $salePriceSnapshot,
                'effective_price_snapshot' => $effectivePrice,
                'line_total'               => $lineTotal,
            ]);
        }

        // Recalculate coupon discount if coupon is applied (subtotal may have changed)
        $this->recalculateCouponDiscount($cart);

        // Return refreshed cart
        return $this->getCart($request);
    }

    /**
     * POST /api/shop/cart/items/{cartItemId}/update
     * Update item quantity.
     */
    public function updateItem(Request $request, $cartItemId)
    {
        $user = $request->user();

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::where('id', $cartItemId)
            ->whereHas('cart', function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', Cart::STATUS_ACTIVE);
            })
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        $product = Product::find($cartItem->product_id);

        if (!$product) {
            return response()->json(['message' => 'Product no longer exists.'], 422);
        }

        if ($validated['quantity'] > $product->stock_quantity) {
            return response()->json([
                'message' => "Only {$product->stock_quantity} available for \"{$product->name}\".",
            ], 422);
        }

        // Refresh price snapshot
        $effectivePrice = $product->sale_price !== null ? $product->sale_price : $product->price;

        $cartItem->update([
            'quantity'                 => $validated['quantity'],
            'price_snapshot'           => $product->price,
            'sale_price_snapshot'      => $product->sale_price,
            'effective_price_snapshot' => $effectivePrice,
            'line_total'               => $effectivePrice * $validated['quantity'],
        ]);

        // Recalculate coupon discount
        $cart = Cart::find($cartItem->cart_id);
        if ($cart) {
            $this->recalculateCouponDiscount($cart);
        }

        return $this->getCart($request);
    }

    /**
     * DELETE /api/shop/cart/items/{cartItemId}
     * Remove item from cart.
     */
    public function removeItem(Request $request, $cartItemId)
    {
        $user = $request->user();

        $cartItem = CartItem::where('id', $cartItemId)
            ->whereHas('cart', function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('status', Cart::STATUS_ACTIVE);
            })
            ->first();

        if (!$cartItem) {
            return response()->json(['message' => 'Cart item not found.'], 404);
        }

        $cartId = $cartItem->cart_id;
        $cartItem->delete();

        // Recalculate coupon discount
        $cart = Cart::find($cartId);
        if ($cart) {
            $this->recalculateCouponDiscount($cart);
        }

        return $this->getCart($request);
    }

    /**
     * DELETE /api/shop/cart/clear
     * Clear all items from the active cart.
     */
    public function clearCart(Request $request)
    {
        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)
            ->where('status', Cart::STATUS_ACTIVE)
            ->first();

        if ($cart) {
            $cart->items()->delete();
            // Remove coupon since cart is empty
            $cart->update([
                'coupon_id'       => null,
                'coupon_code'     => null,
                'discount_amount' => 0,
            ]);
        }

        return $this->getCart($request);
    }

    /**
     * POST /api/shop/cart/apply-coupon
     * Apply a coupon code to the active cart.
     */
    public function applyCoupon(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'code' => 'required|string|max:50',
        ]);

        // Get active cart
        $cart = Cart::where('user_id', $user->id)
            ->where('status', Cart::STATUS_ACTIVE)
            ->with('items')
            ->first();

        if (!$cart) {
            return response()->json(['message' => 'No active cart found.'], 422);
        }

        if ($cart->items->isEmpty()) {
            return response()->json(['message' => 'Your cart is empty.'], 422);
        }

        // Find coupon
        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (!$coupon) {
            return response()->json(['message' => 'Invalid coupon code.'], 422);
        }

        // Validate coupon
        if (!$coupon->isCurrentlyValid()) {
            return response()->json(['message' => 'This coupon is not currently active or has expired.'], 422);
        }

        if ($coupon->isUsageLimitReached()) {
            return response()->json(['message' => 'This coupon has reached its usage limit.'], 422);
        }

        if ($coupon->isPerUserLimitReached($user->id)) {
            return response()->json(['message' => 'You have already used this coupon the maximum number of times.'], 422);
        }

        // Check minimum order amount
        $subtotal = (float) $cart->subtotal;
        if ($subtotal < (float) $coupon->min_order_amount) {
            return response()->json([
                'message' => "Minimum order amount of ₹{$coupon->min_order_amount} is required to use this coupon.",
            ], 422);
        }

        // Calculate discount
        $discountAmount = $coupon->calculateDiscount($subtotal);

        // Save coupon on cart
        $cart->update([
            'coupon_id'       => $coupon->id,
            'coupon_code'     => $coupon->code,
            'discount_amount' => $discountAmount,
        ]);

        return $this->getCart($request);
    }

    /**
     * DELETE /api/shop/cart/remove-coupon
     * Remove coupon from the active cart.
     */
    public function removeCoupon(Request $request)
    {
        $user = $request->user();

        $cart = Cart::where('user_id', $user->id)
            ->where('status', Cart::STATUS_ACTIVE)
            ->first();

        if (!$cart) {
            return response()->json(['message' => 'No active cart found.'], 422);
        }

        $cart->update([
            'coupon_id'       => null,
            'coupon_code'     => null,
            'discount_amount' => 0,
        ]);

        return $this->getCart($request);
    }

    /**
     * Recalculate coupon discount when cart items change.
     */
    private function recalculateCouponDiscount(Cart $cart): void
    {
        if (!$cart->coupon_id) {
            return;
        }

        $cart->load('items');

        // If cart is now empty, remove coupon
        if ($cart->items->isEmpty()) {
            $cart->update([
                'coupon_id'       => null,
                'coupon_code'     => null,
                'discount_amount' => 0,
            ]);
            return;
        }

        $coupon = Coupon::find($cart->coupon_id);

        if (!$coupon || !$coupon->isCurrentlyValid()) {
            // Coupon is no longer valid, remove it
            $cart->update([
                'coupon_id'       => null,
                'coupon_code'     => null,
                'discount_amount' => 0,
            ]);
            return;
        }

        $subtotal = (float) $cart->subtotal;

        // If subtotal no longer meets minimum, remove coupon
        if ($subtotal < (float) $coupon->min_order_amount) {
            $cart->update([
                'coupon_id'       => null,
                'coupon_code'     => null,
                'discount_amount' => 0,
            ]);
            return;
        }

        $discountAmount = $coupon->calculateDiscount($subtotal);
        $cart->update(['discount_amount' => $discountAmount]);
    }
}
