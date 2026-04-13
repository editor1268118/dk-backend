<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'professional_title',
        'category',
        'bio',
        'experience_years',
        'is_verified',
        'verification_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
