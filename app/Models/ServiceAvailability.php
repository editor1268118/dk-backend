<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated Old provider availability slots model. Deprecated under new booking flow.
 *
 * Under the new flow, vendors do NOT create availability slots.
 * This model and its underlying table are kept for backward compatibility.
 * Do NOT use for new features.
 */
class ServiceAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'provider_user_id',
        'available_date',
        'start_time',
        'end_time',
        'max_bookings',
        'is_available',
        'notes',
    ];

    /**
     * Get the service that owns the availability slot.
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the provider (user) who owns the availability slot.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Get the bookings for the availability slot.
     */
    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
