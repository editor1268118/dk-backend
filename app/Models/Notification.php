<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory;

    // =========================================================================
    // PRIORITY CONSTANTS
    // =========================================================================

    const PRIORITY_LOW    = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH   = 'high';
    const PRIORITY_URGENT = 'urgent';

    const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    // =========================================================================
    // TYPE CONSTANTS — Service Marketplace
    // =========================================================================

    const TYPE_SERVICE_BOOKING_CREATED       = 'service_booking_created';
    const TYPE_SERVICE_JOB_OFFER_RECEIVED    = 'service_job_offer_received';
    const TYPE_SERVICE_VENDOR_ACCEPTED       = 'service_vendor_accepted';
    const TYPE_SERVICE_BOOKING_CONFIRMED     = 'service_booking_confirmed';
    const TYPE_SERVICE_RESCHEDULE_REQUESTED  = 'service_reschedule_requested';
    const TYPE_SERVICE_RESCHEDULE_ACCEPTED   = 'service_reschedule_accepted';
    const TYPE_SERVICE_RESCHEDULE_REJECTED   = 'service_reschedule_rejected';
    const TYPE_SERVICE_OTP_AVAILABLE         = 'service_otp_available';
    const TYPE_SERVICE_JOB_STARTED           = 'service_job_started';
    const TYPE_SERVICE_JOB_COMPLETED         = 'service_job_completed';
    const TYPE_SERVICE_PAYOUT_RELEASED       = 'service_payout_released';
    const TYPE_SERVICE_PENALTY_DEDUCTED      = 'service_penalty_deducted';
    const TYPE_SERVICE_ISSUE_REPORTED        = 'service_issue_reported';
    const TYPE_SERVICE_ISSUE_RESOLVED        = 'service_issue_resolved';

    // =========================================================================
    // TYPE CONSTANTS — Shop / E-Commerce
    // =========================================================================

    const TYPE_SHOP_ORDER_PLACED          = 'shop_order_placed';
    const TYPE_SHOP_ORDER_STATUS_UPDATED  = 'shop_order_status_updated';
    const TYPE_SHOP_ORDER_DELIVERED       = 'shop_order_delivered';
    const TYPE_SHOP_ORDER_CANCELLED       = 'shop_order_cancelled';
    const TYPE_SHOP_RETURN_REQUESTED      = 'shop_return_requested';
    const TYPE_SHOP_RETURN_APPROVED       = 'shop_return_approved';
    const TYPE_SHOP_RETURN_REJECTED       = 'shop_return_rejected';
    const TYPE_SHOP_RETURN_COMPLETED      = 'shop_return_completed';
    const TYPE_SHOP_REFUND_STATUS_UPDATED = 'shop_refund_status_updated';
    const TYPE_SHOP_REFUND_PROCESSED      = 'shop_refund_processed';
    const TYPE_SHOP_REVIEW_SUBMITTED      = 'shop_review_submitted';
    const TYPE_SHOP_PRODUCT_LOW_STOCK     = 'shop_product_low_stock';

    // =========================================================================
    // TYPE CONSTANTS — Admin / System
    // =========================================================================

    const TYPE_ADMIN_ALERT = 'admin_alert';

    // =========================================================================
    // FILLABLE
    // =========================================================================

    protected $fillable = [
        'user_id',
        'title',
        'message',
        'type',
        'priority',
        'entity_type',
        'entity_id',
        'action_url',
        'data',
        'read_at',
    ];

    // =========================================================================
    // CASTS
    // =========================================================================

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * The user this notification belongs to.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Whether the notification has been read.
     */
    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to unread notifications.
     */
    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    /**
     * Scope to read notifications.
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter by priority.
     */
    public function scopeOfPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
