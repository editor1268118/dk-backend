<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingVendorOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'provider_user_id',
        'status',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'expired_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    /**
     * Offer status constants.
     */
    const STATUS_SENT = 'sent';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    /**
     * The booking this offer belongs to.
     */
    public function booking()
    {
        return $this->belongsTo(ServiceBooking::class, 'booking_id');
    }

    /**
     * The provider (vendor) this offer was sent to.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }
}
