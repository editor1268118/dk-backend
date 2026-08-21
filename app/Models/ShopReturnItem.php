<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_return_request_id',
        'shop_order_item_id',
        'product_id',
        'quantity',
        'reason',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'shop_return_request_id' => 'integer',
        'shop_order_item_id'     => 'integer',
        'product_id'             => 'integer',
        'quantity'               => 'integer',
        'unit_price'             => 'decimal:2',
        'line_total'             => 'decimal:2',
    ];

    /**
     * Get the return request this item belongs to.
     */
    public function returnRequest()
    {
        return $this->belongsTo(ShopReturnRequest::class, 'shop_return_request_id');
    }

    /**
     * Get the original order item.
     */
    public function orderItem()
    {
        return $this->belongsTo(ShopOrderItem::class, 'shop_order_item_id');
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
