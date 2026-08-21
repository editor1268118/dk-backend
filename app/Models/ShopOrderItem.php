<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_order_id',
        'product_id',
        'product_name',
        'product_sku',
        'product_image',
        'quantity',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'shop_order_id' => 'integer',
        'product_id'    => 'integer',
        'quantity'      => 'integer',
        'unit_price'    => 'decimal:2',
        'line_total'    => 'decimal:2',
    ];

    /**
     * Get the order this item belongs to.
     */
    public function order()
    {
        return $this->belongsTo(ShopOrder::class, 'shop_order_id');
    }

    /**
     * Get the product (nullable — product may be deleted later).
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get image URL for the snapshot image.
     */
    public function getProductImageUrlAttribute(): ?string
    {
        if (!$this->product_image) {
            return null;
        }

        if (str_starts_with($this->product_image, 'http')) {
            return $this->product_image;
        }

        return asset('storage/' . $this->product_image);
    }
}
