<?php

namespace App\Services;

use App\Models\ProfessionalWallet;
use App\Models\ProfessionalWalletTransaction;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Get the provider's wallet, or create one if it doesn't exist.
     *
     * @param int $providerUserId
     * @return ProfessionalWallet
     */
    public function getOrCreateWallet(int $providerUserId): ProfessionalWallet
    {
        return ProfessionalWallet::firstOrCreate(
            ['provider_user_id' => $providerUserId],
            [
                'balance' => 0.00,
                'currency' => 'INR',
                'is_active' => true,
            ]
        );
    }

    /**
     * Perform a mock recharge on the provider's wallet.
     *
     * @param int $providerUserId
     * @param float $amount
     * @param string $description
     * @return ProfessionalWallet
     */
    public function rechargeMock(int $providerUserId, float $amount, string $description = "Mock wallet recharge"): ProfessionalWallet
    {
        return DB::transaction(function () use ($providerUserId, $amount, $description) {
            // Retrieve and lock wallet to prevent race conditions during recharge
            $wallet = ProfessionalWallet::where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                // If it doesn't exist in DB yet, create it
                $wallet = $this->getOrCreateWallet($providerUserId);
                // Relock it
                $wallet = ProfessionalWallet::where('id', $wallet->id)
                    ->lockForUpdate()
                    ->first();
            }

            $balanceBefore = (float) $wallet->balance;
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update([
                'balance' => $balanceAfter,
            ]);

            ProfessionalWalletTransaction::create([
                'provider_user_id' => $providerUserId,
                'wallet_id' => $wallet->id,
                'booking_id' => null,
                'type' => 'recharge',
                'amount' => $amount,
                'direction' => 'credit',
                'description' => $description,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => 'RECHARGE-' . time() . '-' . rand(1000, 9999),
            ]);

            return $wallet->fresh();
        });
    }

    /**
     * Charge a cancellation penalty to the provider's wallet.
     *
     * @param int $providerUserId
     * @param int|null $bookingId
     * @param float $amount
     * @param string $description
     * @return ProfessionalWallet
     */
    public function chargePenalty(int $providerUserId, ?int $bookingId, float $amount, string $description = "10% penalty for vendor cancellation after confirmation"): ProfessionalWallet
    {
        return DB::transaction(function () use ($providerUserId, $bookingId, $amount, $description) {
            // Retrieve and lock wallet
            $wallet = ProfessionalWallet::where('provider_user_id', $providerUserId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $wallet = $this->getOrCreateWallet($providerUserId);
                $wallet = ProfessionalWallet::where('id', $wallet->id)
                    ->lockForUpdate()
                    ->first();
            }

            $balanceBefore = (float) $wallet->balance;
            $balanceAfter = $balanceBefore - $amount; // Wallet CAN go negative

            $wallet->update([
                'balance' => $balanceAfter,
            ]);

            ProfessionalWalletTransaction::create([
                'provider_user_id' => $providerUserId,
                'wallet_id' => $wallet->id,
                'booking_id' => $bookingId,
                'type' => 'penalty_debit',
                'amount' => $amount,
                'direction' => 'debit',
                'description' => $description,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => 'PENALTY-' . $bookingId . '-' . time(),
            ]);

            return $wallet->fresh();
        });
    }
}
