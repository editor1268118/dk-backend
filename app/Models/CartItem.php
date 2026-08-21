<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price_snapshot',
        'sale_price_snapshot',
        'effective_price_snapshot',
        'line_total',
    ];

    protected $casts = [
        'cart_id'                  => 'integer',
        'product_id'               => 'integer',
        'quantity'                 => 'integer',
        'price_snapshot'           => 'decimal:2',
        'sale_price_snapshot'      => 'decimal:2',
        'effective_price_snapshot' => 'decimal:2',
        'line_total'               => 'decimal:2',
    ];

    /**
     * Get the cart this item belongs to.
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Get the product for this cart item.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
