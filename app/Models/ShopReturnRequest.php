<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopReturnRequest extends Model
{
    use HasFactory;

    /**
     * Return request statuses.
     */
    const STATUS_REQUESTED = 'requested';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_RETURNED  = 'returned';
    const STATUS_CANCELLED = 'cancelled';

    const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'return_number',
        'shop_order_id',
        'user_id',
        'status',
        'reason',
        'customer_note',
        'admin_note',
        'refund_amount_requested',
        'refund_amount_approved',
        'requested_at',
        'approved_at',
        'rejected_at',
        'returned_at',
        'resolved_by',
    ];

    protected $casts = [
        'shop_order_id'          => 'integer',
        'user_id'                => 'integer',
        'refund_amount_requested'=> 'decimal:2',
        'refund_amount_approved' => 'decimal:2',
        'requested_at'           => 'datetime',
        'approved_at'            => 'datetime',
        'rejected_at'            => 'datetime',
        'returned_at'            => 'datetime',
        'resolved_by'            => 'integer',
    ];

    /**
     * Get the order this return belongs to.
     */
    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Get the user who requested the return.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get return items.
     */
    public function items()
    {
        return $this->hasMany(ShopReturnItem::class);
    }

    /**
     * Get refunds linked to this return.
     */
    public function refunds()
    {
        return $this->hasMany(ShopRefund::class);
    }

    /**
     * Get the admin who resolved this return.
     */
    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Generate a unique return number: DKRET-YYYYMMDD-NNNNNN
     */
    public static function generateReturnNumber(): string
    {
        $datePrefix = 'DKRET-' . now()->format('Ymd') . '-';

        $todayCount = self::where('return_number', 'like', $datePrefix . '%')->count();
        $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);

        $returnNumber = $datePrefix . $sequence;

        while (self::where('return_number', $returnNumber)->exists()) {
            $todayCount++;
            $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);
            $returnNumber = $datePrefix . $sequence;
        }

        return $returnNumber;
    }
}
