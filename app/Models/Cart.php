<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    const STATUS_ACTIVE    = 'active';
    const STATUS_CONVERTED = 'converted';
    const STATUS_ABANDONED = 'abandoned';

    const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CONVERTED,
        self::STATUS_ABANDONED,
    ];

    protected $fillable = [
        'user_id',
        'status',
        'coupon_id',
        'coupon_code',
        'discount_amount',
    ];

    protected $casts = [
        'user_id'         => 'integer',
        'coupon_id'       => 'integer',
        'discount_amount' => 'decimal:2',
    ];

    /**
     * Get the user who owns this cart.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the coupon applied to this cart.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get all items in this cart.
     */
    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * Calculate cart subtotal from all item line totals.
     */
    public function getSubtotalAttribute(): string
    {
        return number_format((float) $this->items->sum('line_total'), 2, '.', '');
    }

    /**
     * Calculate total after discount.
     */
    public function getTotalAfterDiscountAttribute(): string
    {
        $subtotal = (float) $this->subtotal;
        $discount = (float) ($this->discount_amount ?? 0);
        $total = max($subtotal - $discount, 0);
        return number_format($total, 2, '.', '');
    }

    /**
     * Calculate total quantity of items in cart.
     */
    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }
}
