<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProfessionalWalletController extends Controller
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    /**
     * GET /api/professional/wallet
     *
     * Get the authenticated provider's wallet details.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        // Security check
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        // Get or create wallet
        $wallet = $this->walletService->getOrCreateWallet($user->id);

        $balance = (float) $wallet->balance;
        $minimumRequired = 50.00;
        $canAcceptJobs = $balance >= $minimumRequired;

        // Fetch recent 10 transactions
        $recentTransactions = $wallet->transactions()
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'wallet' => [
                'id' => $wallet->id,
                'balance' => $balance,
                'currency' => $wallet->currency,
                'is_active' => (bool) $wallet->is_active,
                'minimum_required_balance' => $minimumRequired,
                'can_accept_jobs' => $canAcceptJobs,
                'recent_transactions' => $recentTransactions,
            ]
        ], 200);
    }

    /**
     * GET /api/professional/wallet/transactions
     *
     * Get the full transaction history of the provider's wallet (latest first).
     */
    public function transactions(Request $request)
    {
        $user = $request->user();

        // Security check
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        // Get wallet
        $wallet = $this->walletService->getOrCreateWallet($user->id);

        $transactions = $wallet->transactions()
            ->with(['booking:id,booking_reference'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'amount' => (float) $tx->amount,
                    'direction' => $tx->direction,
                    'description' => $tx->description,
                    'balance_before' => (float) $tx->balance_before,
                    'balance_after' => (float) $tx->balance_after,
                    'reference' => $tx->reference,
                    'booking' => $tx->booking ? [
                        'id' => $tx->booking->id,
                        'booking_reference' => $tx->booking->booking_reference,
                    ] : null,
                    'created_at' => $tx->created_at,
                ];
            });

        return response()->json([
            'transactions' => $transactions,
            'total' => $transactions->count(),
        ], 200);
    }

    /**
     * POST /api/professional/wallet/recharge-mock
     *
     * Mock recharge API for development.
     */
    public function rechargeMock(Request $request)
    {
        $user = $request->user();

        // Security check
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        // Validate request
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $amount = (float) $request->input('amount');

        // Recharge wallet
        $wallet = $this->walletService->rechargeMock($user->id, $amount);

        $balance = (float) $wallet->balance;
        $minimumRequired = 50.00;
        $canAcceptJobs = $balance >= $minimumRequired;

        return response()->json([
            'message' => 'Mock wallet recharge successful.',
            'wallet' => [
                'id' => $wallet->id,
                'balance' => $balance,
                'currency' => $wallet->currency,
                'is_active' => (bool) $wallet->is_active,
                'minimum_required_balance' => $minimumRequired,
                'can_accept_jobs' => $canAcceptJobs,
            ]
        ], 200);
    }
}
