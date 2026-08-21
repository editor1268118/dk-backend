<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrderStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'shop_order_status_histories';

    protected $fillable = [
        'shop_order_id',
        'old_status',
        'new_status',
        'note',
        'changed_by',
        'changed_by_role',
    ];

    protected $casts = [
        'shop_order_id' => 'integer',
        'changed_by'    => 'integer',
    ];

    /**
     * Get the order this history entry belongs to.
     */
    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Get the user who made the status change.
     */
    public function changedByUser()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
