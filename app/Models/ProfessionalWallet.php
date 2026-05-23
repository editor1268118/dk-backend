<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalWallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_user_id',
        'balance',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the provider user that owns this wallet.
     */
    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Get the transactions for this wallet.
     */
    public function transactions()
    {
        return $this->hasMany(ProfessionalWalletTransaction::class, 'wallet_id');
    }
}
