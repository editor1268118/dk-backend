<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopRefund extends Model
{
    use HasFactory;

    /**
     * Refund statuses.
     */
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_PROCESSED = 'processed';
    const STATUS_REJECTED = 'rejected';

    const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_PROCESSED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'refund_number',
        'shop_order_id',
        'shop_return_request_id',
        'user_id',
        'amount',
        'status',
        'refund_method',
        'refund_reference',
        'admin_note',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'shop_order_id'          => 'integer',
        'shop_return_request_id' => 'integer',
        'user_id'                => 'integer',
        'amount'                 => 'decimal:2',
        'processed_at'           => 'datetime',
        'processed_by'           => 'integer',
    ];

    /**
     * Get the order this refund belongs to.
     */
    public function shopOrder()
    {
        return $this->belongsTo(ShopOrder::class);
    }

    /**
     * Get the return request associated with this refund.
     */
    public function returnRequest()
    {
        return $this->belongsTo(ShopReturnRequest::class, 'shop_return_request_id');
    }

    /**
     * Get the user who gets this refund.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who processed this refund.
     */
    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Generate a unique refund number: DKREF-YYYYMMDD-NNNNNN
     */
    public static function generateRefundNumber(): string
    {
        $datePrefix = 'DKREF-' . now()->format('Ymd') . '-';

        $todayCount = self::where('refund_number', 'like', $datePrefix . '%')->count();
        $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);

        $refundNumber = $datePrefix . $sequence;

        while (self::where('refund_number', $refundNumber)->exists()) {
            $todayCount++;
            $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);
            $refundNumber = $datePrefix . $sequence;
        }

        return $refundNumber;
    }
}
