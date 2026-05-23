<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfessionalWalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_user_id',
        'wallet_id',
        'booking_id',
        'type',
        'amount',
        'direction',
        'description',
        'balance_before',
        'balance_after',
        'reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /**
     * Get the provider user associated with this transaction.
     */
    public function providerUser()
    {
        return $this->belongsTo(User::class, 'provider_user_id');
    }

    /**
     * Get the wallet associated with this transaction.
     */
    public function wallet()
    {
        return $this->belongsTo(ProfessionalWallet::class, 'wallet_id');
    }

    /**
     * Get the service booking associated with this transaction (nullable).
     */
    public function booking()
    {
        return $this->belongsTo(ServiceBooking::class, 'booking_id');
    }
}
