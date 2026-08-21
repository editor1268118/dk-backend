<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryLog extends Model
{
    use HasFactory;

    /**
     * Valid change types.
     */
    const TYPE_CREATE               = 'create';
    const TYPE_MANUAL_ADJUSTMENT     = 'manual_adjustment';
    const TYPE_STOCK_UPDATE          = 'stock_update';
    const TYPE_ORDER_DEDUCTION       = 'order_deduction';
    const TYPE_ORDER_CANCEL_RESTORE  = 'order_cancel_restore';
    const TYPE_RETURN_RESTORE        = 'return_restore';

    const CHANGE_TYPES = [
        self::TYPE_CREATE,
        self::TYPE_MANUAL_ADJUSTMENT,
        self::TYPE_STOCK_UPDATE,
        self::TYPE_ORDER_DEDUCTION,
        self::TYPE_ORDER_CANCEL_RESTORE,
        self::TYPE_RETURN_RESTORE,
    ];

    protected $fillable = [
        'product_id',
        'change_type',
        'quantity_before',
        'quantity_after',
        'quantity_changed',
        'reason',
        'admin_user_id',
    ];

    protected $casts = [
        'product_id'       => 'integer',
        'quantity_before'  => 'integer',
        'quantity_after'   => 'integer',
        'quantity_changed' => 'integer',
        'admin_user_id'    => 'integer',
    ];

    /**
     * Get the product this log belongs to.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the admin user who made the change.
     */
    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
