<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CouponUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'shop_order_id',
        'discount_amount',
        'used_at',
    ];

    protected $casts = [
        'coupon_id'       => 'integer',
        'user_id'         => 'integer',
        'shop_order_id'   => 'integer',
        'discount_amount' => 'decimal:2',
        'used_at'         => 'datetime',
    ];

    /**
     * Get the coupon that was used.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get the user who used the coupon.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order associated with this usage.
     */
    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }
}
