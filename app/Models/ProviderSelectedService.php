<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderSelectedService extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_user_id',
        'platform_service_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the provider (user) who selected this service.
     */
    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Get the platform service that was selected.
     */
    public function platformService()
    {
        return $this->belongsTo(PlatformService::class);
    }
}
