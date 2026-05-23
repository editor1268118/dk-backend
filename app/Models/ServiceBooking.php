<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ServiceBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_reference',
        'user_id',
        'platform_service_id',
        'assigned_provider_user_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'service_country',
        'service_state',
        'service_city',
        'service_pincode',
        'service_address_line_1',
        'service_address_line_2',
        'preferred_date',
        'preferred_time',
        'customer_price',
        'vendor_payout_percentage',
        'vendor_expected_payout',
        'platform_amount',
        'payment_status',
        'status',
        'notes',
        'cancellation_rule_version',
        'vendor_accepted_at',
        'action_window_ends_at',
        'confirmed_at',
        'vendor_window_cancelled_at',
        'vendor_change_requested_at',
        'vendor_change_reason',
        'cancellation_reason',
        'cancelled_at',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'customer_price' => 'decimal:2',
        'vendor_payout_percentage' => 'decimal:2',
        'vendor_expected_payout' => 'decimal:2',
        'platform_amount' => 'decimal:2',
        'vendor_accepted_at' => 'datetime',
        'action_window_ends_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'vendor_window_cancelled_at' => 'datetime',
        'vendor_change_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Booking status constants.
     */
    const STATUS_JOB_CREATED = 'job_created';
    const STATUS_PAYMENT_PENDING = 'payment_pending';
    const STATUS_PAID = 'paid';
    const STATUS_BROADCASTED = 'broadcasted';
    const STATUS_VENDOR_ACCEPTED = 'vendor_accepted';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED_WITHIN_WINDOW = 'cancelled_within_window';
    const STATUS_CHANGE_REQUESTED = 'change_requested';
    const STATUS_NO_VENDOR_AVAILABLE = 'no_vendor_available';
    const STATUS_CANCELLED_BY_VENDOR = 'cancelled_by_vendor';

    /**
     * Payment status constants.
     */
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_PAID = 'paid';

    /**
     * Generate a unique booking reference.
     */
    public static function generateBookingReference(): string
    {
        do {
            $reference = 'DK-' . strtoupper(Str::random(8));
        } while (self::where('booking_reference', $reference)->exists());

        return $reference;
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    /**
     * All wallet transactions associated with this booking.
     */
    public function walletTransactions()
    {
        return $this->hasMany(ProfessionalWalletTransaction::class, 'booking_id');
    }

    /**
     * The user (customer) who made this booking.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The platform service booked.
     */
    public function platformService()
    {
        return $this->belongsTo(PlatformService::class);
    }

    /**
     * The provider assigned to this booking (nullable until accepted).
     */
    public function assignedProvider()
    {
        return $this->belongsTo(User::class, 'assigned_provider_user_id');
    }

    /**
     * All vendor offers for this booking.
     */
    public function vendorOffers()
    {
        return $this->hasMany(BookingVendorOffer::class, 'booking_id');
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Check if a vendor has already been assigned.
     */
    public function isAssigned(): bool
    {
        return !is_null($this->assigned_provider_user_id);
    }

    /**
     * Check if the booking is in a broadcastable state.
     */
    public function isBroadcastable(): bool
    {
        return in_array($this->status, [self::STATUS_PAID, self::STATUS_BROADCASTED]);
    }
}
