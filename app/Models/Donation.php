<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Donation extends Model
{
    use HasFactory;

    protected $fillable = [
        'donor_user_id',
        'fundraiser_user_id',
        'amount',
        'currency',
        'payment_status',
        'transaction_reference',
        'donor_name',
        'donor_email',
        'donor_phone',
        'message',
        'is_anonymous',
    ];

    public function donor()
    {
        return $this->belongsTo(User::class, 'donor_user_id');
    }

    public function fundraiser()
    {
        return $this->belongsTo(User::class, 'fundraiser_user_id');
    }
}
