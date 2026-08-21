<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'user_id',
        'shop_order_id',
        'shop_order_item_id',
        'rating',
        'comment',
        'is_visible',
        'verified_purchase',
    ];

    protected $casts = [
        'product_id'        => 'integer',
        'user_id'           => 'integer',
        'shop_order_id'     => 'integer',
        'shop_order_item_id'=> 'integer',
        'rating'            => 'integer',
        'is_visible'        => 'boolean',
        'verified_purchase' => 'boolean',
    ];

    /**
     * Get the product being reviewed.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who wrote the review.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the order associated with this review.
     */
    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Get the specific order item being reviewed.
     */
    public function shopOrderItem()
    {
        return $this->belongsTo(ShopOrderItem::class);
    }
}
