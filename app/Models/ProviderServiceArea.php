<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderServiceArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_user_id',
        'country',
        'state',
        'city',
        'pincode',
        'latitude',
        'longitude',
        'radius_km',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'radius_km' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the provider (user) who owns this service area.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }
}
