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

            // Phase 11: Notify about penalty deduction + low balance
            try {
                $notificationService = app(NotificationService::class);
                $notifOptions = [
                    'entity_type' => 'service_booking',
                    'entity_id'   => $bookingId,
                    'action_url'  => '/dashboard?tab=wallet',
                ];

                // Notify professional
                $notificationService->notifyProvider(
                    $providerUserId,
                    'Penalty deducted',
                    "₹{$amount} penalty has been deducted from your wallet. {$description}",
                    \App\Models\Notification::TYPE_SERVICE_PENALTY_DEDUCTED,
                    $notifOptions
                );

                // Notify admins
                $notificationService->notifyAdmins(
                    'Penalty deducted',
                    "₹{$amount} penalty deducted from provider #{$providerUserId} wallet.",
                    \App\Models\Notification::TYPE_SERVICE_PENALTY_DEDUCTED,
                    array_merge($notifOptions, ['action_url' => '/admin/control-panel?section=wallets'])
                );

                // Low wallet balance warning (below ₹50)
                if ($balanceAfter < 50) {
                    $notificationService->notifyProvider(
                        $providerUserId,
                        'Low wallet balance',
                        'Your wallet balance is below ₹50. Recharge to accept jobs.',
                        \App\Models\Notification::TYPE_SERVICE_PENALTY_DEDUCTED,
                        [
                            'priority'   => \App\Models\Notification::PRIORITY_HIGH,
                            'action_url' => '/dashboard?tab=wallet',
                        ]
                    );
                }
            } catch (\Exception $e) {
                // Never fail core action
            }

            return $wallet->fresh();
        });
    }

    /**
     * Credit payout to the provider's wallet for a completed booking.
     *
     * Phase 7: Completion-Based Payout Release.
     *
     * @param int $providerUserId
     * @param int $bookingId
     * @param float $amount
     * @param string $reference  Unique payout release reference
     * @param string $description
     * @return ProfessionalWallet
     */
    public function creditPayout(int $providerUserId, int $bookingId, float $amount, string $reference, string $description = "Payout released for completed booking"): ProfessionalWallet
    {
        return DB::transaction(function () use ($providerUserId, $bookingId, $amount, $reference, $description) {
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
            $balanceAfter = $balanceBefore + $amount;

            $wallet->update([
                'balance' => $balanceAfter,
            ]);

            ProfessionalWalletTransaction::create([
                'provider_user_id' => $providerUserId,
                'wallet_id' => $wallet->id,
                'booking_id' => $bookingId,
                'type' => 'payout_credit',
                'amount' => $amount,
                'direction' => 'credit',
                'description' => $description,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => $reference,
            ]);

            return $wallet->fresh();
        });
    }

    /**
     * Admin wallet adjustment — credit or debit with type 'adjustment'.
     *
     * @param int    $providerUserId
     * @param float  $amount        Positive amount
     * @param string $direction     'credit' or 'debit'
     * @param string $reason        Admin-supplied reason
     * @return ProfessionalWallet
     */
    public function adjustBalance(int $providerUserId, float $amount, string $direction, string $reason): ProfessionalWallet
    {
        return DB::transaction(function () use ($providerUserId, $amount, $direction, $reason) {
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
            $balanceAfter = $direction === 'credit'
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            $wallet->update([
                'balance' => $balanceAfter,
            ]);

            ProfessionalWalletTransaction::create([
                'provider_user_id' => $providerUserId,
                'wallet_id' => $wallet->id,
                'booking_id' => null,
                'type' => 'adjustment',
                'amount' => $amount,
                'direction' => $direction,
                'description' => $reason,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'reference' => 'ADJ-' . time() . '-' . rand(1000, 9999),
            ]);

            return $wallet->fresh();
        });
    }
}
