<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'user_id',
        'provider_user_id',
        'rating',
        'comment',
        'is_visible',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_visible' => 'boolean',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    /**
     * The booking this review belongs to.
     */
    public function booking()
    {
        return $this->belongsTo(ServiceBooking::class, 'booking_id');
    }

    /**
     * The customer who submitted this review.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The provider who was reviewed.
     */
    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }
}
