<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShopOrder extends Model
{
    use HasFactory;

    /**
     * Order statuses.
     */
    const STATUS_PENDING    = 'pending';
    const STATUS_PAID       = 'paid';
    const STATUS_PROCESSING = 'processing';
    const STATUS_PACKED     = 'packed';
    const STATUS_SHIPPED    = 'shipped';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_CANCELLED  = 'cancelled';

    const ORDER_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_PROCESSING,
        self::STATUS_PACKED,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Payment statuses.
     */
    const PAYMENT_PENDING  = 'pending';
    const PAYMENT_PAID     = 'paid';
    const PAYMENT_FAILED   = 'failed';
    const PAYMENT_REFUNDED = 'refunded';

    const PAYMENT_STATUSES = [
        self::PAYMENT_PENDING,
        self::PAYMENT_PAID,
        self::PAYMENT_FAILED,
        self::PAYMENT_REFUNDED,
    ];

    /**
     * Refund statuses.
     */
    const REFUND_NOT_APPLICABLE = 'not_applicable';
    const REFUND_PENDING        = 'pending';
    const REFUND_APPROVED       = 'approved';
    const REFUND_PROCESSED      = 'processed';
    const REFUND_REJECTED       = 'rejected';

    const REFUND_STATUSES = [
        self::REFUND_NOT_APPLICABLE,
        self::REFUND_PENDING,
        self::REFUND_APPROVED,
        self::REFUND_PROCESSED,
        self::REFUND_REJECTED,
    ];

    /**
     * Return statuses.
     */
    const RETURN_NONE              = 'none';
    const RETURN_REQUESTED         = 'return_requested';
    const RETURN_APPROVED          = 'return_approved';
    const RETURN_REJECTED          = 'return_rejected';
    const RETURN_RETURNED          = 'returned';

    const RETURN_STATUSES = [
        self::RETURN_NONE,
        self::RETURN_REQUESTED,
        self::RETURN_APPROVED,
        self::RETURN_REJECTED,
        self::RETURN_RETURNED,
    ];

    protected $fillable = [
        'order_number',
        'user_id',
        'cart_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_name',
        'shipping_phone',
        'shipping_address_line_1',
        'shipping_address_line_2',
        'shipping_city',
        'shipping_state',
        'shipping_pincode',
        'shipping_country',
        'subtotal',
        'shipping_charge',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'payment_reference',
        'order_status',
        'admin_note',
        'customer_note',
        'paid_at',
        'packed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'coupon_id',
        'coupon_code',
        'refund_status',
        'return_status',
    ];

    protected $casts = [
        'user_id'         => 'integer',
        'cart_id'         => 'integer',
        'coupon_id'       => 'integer',
        'subtotal'        => 'decimal:2',
        'shipping_charge' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'paid_at'         => 'datetime',
        'packed_at'       => 'datetime',
        'shipped_at'      => 'datetime',
        'delivered_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
    ];

    /**
     * Get the user who placed this order.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the cart associated with this order.
     */
    public function cart()
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * Get all items in this order.
     */
    public function items()
    {
        return $this->hasMany(ShopOrderItem::class);
    }

    /**
     * Get the coupon used for this order.
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /**
     * Get return requests for this order.
     */
    public function returnRequests()
    {
        return $this->hasMany(ShopReturnRequest::class);
    }

    /**
     * Get refunds for this order.
     */
    public function refunds()
    {
        return $this->hasMany(ShopRefund::class);
    }

    /**
     * Get status history for this order.
     */
    public function statusHistories()
    {
        return $this->hasMany(ShopOrderStatusHistory::class)->orderBy('created_at');
    }

    /**
     * Get item count.
     */
    public function getItemCountAttribute(): int
    {
        return $this->items->count();
    }

    /**
     * Generate a unique order number: DKSHOP-YYYYMMDD-NNNNNN
     */
    public static function generateOrderNumber(): string
    {
        $datePrefix = 'DKSHOP-' . now()->format('Ymd') . '-';

        $todayCount = self::where('order_number', 'like', $datePrefix . '%')->count();
        $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);

        $orderNumber = $datePrefix . $sequence;

        // Ensure uniqueness (edge-case safety)
        while (self::where('order_number', $orderNumber)->exists()) {
            $todayCount++;
            $sequence = str_pad($todayCount + 1, 6, '0', STR_PAD_LEFT);
            $orderNumber = $datePrefix . $sequence;
        }

        return $orderNumber;
    }
}
