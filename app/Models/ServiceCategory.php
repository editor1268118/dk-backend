<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * @deprecated Old provider-created services. Deprecated under new flow.
     */
    public function services()
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get all platform services under this category.
     */
    public function platformServices()
    {
        return $this->hasMany(PlatformService::class);
    }
}
