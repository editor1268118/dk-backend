<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformService extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_category_id',
        'name',
        'slug',
        'description',
        'customer_price',
        'vendor_payout_percentage',
        'platform_percentage',
        'is_active',
    ];

    protected $casts = [
        'customer_price' => 'decimal:2',
        'vendor_payout_percentage' => 'decimal:2',
        'platform_percentage' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the service category this platform service belongs to.
     */
    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * Get all provider selections for this platform service.
     */
    public function providerSelectedServices()
    {
        return $this->hasMany(ProviderSelectedService::class);
    }

    /**
     * Get all providers who have selected this service.
     */
    public function providers()
    {
        return $this->belongsToMany(User::class, 'provider_selected_services', 'platform_service_id', 'provider_user_id')
            ->withPivot('is_active')
            ->withTimestamps();
    }

    /**
     * Get all service bookings for this platform service.
     */
    public function serviceBookings()
    {
        return $this->hasMany(ServiceBooking::class);
    }
}
