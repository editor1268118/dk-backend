<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated Old provider-created services model. Deprecated under new booking flow.
 *
 * Under the new flow (Phase 1+), services are managed by Super Admin in the
 * platform_services table via the PlatformService model. Providers select
 * services from platform_services instead of creating their own.
 *
 * This model and its underlying table are kept for backward compatibility.
 * Do NOT use for new features.
 */
class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_user_id',
        'service_category_id',
        'title',
        'slug',
        'short_description',
        'description',
        'price',
        'pricing_type',
        'duration_minutes',
        'is_active',
    ];

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function availabilities()
    {
        return $this->hasMany(ServiceAvailability::class);
    }
}
