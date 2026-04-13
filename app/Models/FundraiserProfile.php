<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundraiserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fundraiser_type',
        'organization_name',
        'registration_number',
        'cause_title',
        'cause_description',
        'is_verified',
        'verification_status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
