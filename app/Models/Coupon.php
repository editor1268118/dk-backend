<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    /**
     * Discount types.
     */
    const TYPE_PERCENTAGE    = 'percentage';
    const TYPE_FIXED_AMOUNT  = 'fixed_amount';
    const TYPE_FREE_SHIPPING = 'free_shipping';

    const DISCOUNT_TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED_AMOUNT,
        self::TYPE_FREE_SHIPPING,
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'min_order_amount',
        'max_discount_amount',
        'usage_limit',
        'used_count',
        'per_user_limit',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'discount_value'     => 'decimal:2',
        'min_order_amount'   => 'decimal:2',
        'max_discount_amount'=> 'decimal:2',
        'usage_limit'        => 'integer',
        'used_count'         => 'integer',
        'per_user_limit'     => 'integer',
        'starts_at'          => 'datetime',
        'ends_at'            => 'datetime',
        'is_active'          => 'boolean',
        'created_by'         => 'integer',
    ];

    /**
     * Get all usage records for this coupon.
     */
    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    /**
     * Get the admin who created this coupon.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if coupon is currently valid (active + within date range).
     */
    public function isCurrentlyValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Check if usage limit has been reached.
     */
    public function isUsageLimitReached(): bool
    {
        if ($this->usage_limit === null) {
            return false;
        }

        return $this->used_count >= $this->usage_limit;
    }

    /**
     * Check if a specific user has exceeded their per-user limit.
     */
    public function isPerUserLimitReached(int $userId): bool
    {
        if ($this->per_user_limit === null) {
            return false;
        }

        $userUsageCount = $this->usages()->where('user_id', $userId)->count();
        return $userUsageCount >= $this->per_user_limit;
    }

    /**
     * Calculate discount amount for a given subtotal.
     */
    public function calculateDiscount(float $subtotal): float
    {
        switch ($this->discount_type) {
            case self::TYPE_PERCENTAGE:
                $discount = $subtotal * ($this->discount_value / 100);
                if ($this->max_discount_amount !== null) {
                    $discount = min($discount, (float) $this->max_discount_amount);
                }
                return round($discount, 2);

            case self::TYPE_FIXED_AMOUNT:
                return round(min((float) $this->discount_value, $subtotal), 2);

            case self::TYPE_FREE_SHIPPING:
                // Free shipping discount is applied at checkout when shipping charge is known.
                // At cart level, store coupon but set discount to 0.
                return 0;

            default:
                return 0;
        }
    }
}
