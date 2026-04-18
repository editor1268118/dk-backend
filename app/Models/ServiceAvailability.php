<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
