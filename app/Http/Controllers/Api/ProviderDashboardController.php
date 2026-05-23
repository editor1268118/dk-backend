<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BookingVendorOffer;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProviderDashboardController extends Controller
{
    /**
     * GET /api/professional/bookings
     *
     * Return bookings assigned to the authenticated provider.
     * Only returns bookings from the new service_bookings flow.
     */
    public function assignedBookings(Request $request)
    {
        $user = $request->user();

        // Verify provider role
        if (!$user->hasRole('provider')) {
            return response()->json([
                'message' => 'Forbidden. You are not a professional provider.',
            ], 403);
        }

        $bookings = ServiceBooking::with([
                'platformService.category',
                'user',
            ])
            ->where('assigned_provider_user_id', $user->id)
            ->orderBy('preferred_date', 'desc')
            ->orderBy('preferred_time', 'desc')
            ->get()
            ->map(function ($booking) {
                $now = now();
                $secondsRemaining = 0;
                $canAction = false;

                if ($booking->status === ServiceBooking::STATUS_VENDOR_ACCEPTED && $booking->action_window_ends_at) {
                    if ($now->lte($booking->action_window_ends_at)) {
                        $secondsRemaining = max(0, $booking->action_window_ends_at->diffInSeconds($now));
                        $canAction = true;
                    }
                }

                return [
                    'id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'customer' => [
                        'name' => $booking->customer_name ?? $booking->user?->name ?? 'Customer',
                        'phone' => $booking->customer_phone ?? $booking->user?->phone ?? 'N/A',
                        'email' => $booking->customer_email ?? $booking->user?->email ?? '',
                    ],
                    'service' => [
                        'id' => $booking->platformService?->id,
                        'name' => $booking->platformService?->name ?? 'Service',
                        'category' => $booking->platformService?->category?->name ?? 'General',
                    ],
                    'preferred_date' => $booking->preferred_date ? $booking->preferred_date->format('Y-m-d') : '',
                    'preferred_time' => $booking->preferred_time,
                    'location' => [
                        'country' => $booking->service_country,
                        'state' => $booking->service_state,
                        'city' => $booking->service_city,
                        'pincode' => $booking->service_pincode,
                        'address_line_1' => $booking->service_address_line_1,
                        'address_line_2' => $booking->service_address_line_2,
                    ],
                    'vendor_expected_payout' => $booking->vendor_expected_payout,
                    'vendor_payout' => $booking->vendor_expected_payout,
                    'expected_payout' => $booking->vendor_expected_payout,
                    'status' => $booking->status,
                    'vendor_accepted_at' => $booking->vendor_accepted_at,
                    'action_window_ends_at' => $booking->action_window_ends_at,
                    'confirmed_at' => $booking->confirmed_at,
                    'vendor_change_reason' => $booking->vendor_change_reason,
                    'can_cancel_within_window' => $canAction,
                    'can_request_change_within_window' => $canAction,
                    'seconds_remaining_in_action_window' => $secondsRemaining,
                    'can_cancel_after_confirmation' => $booking->status === ServiceBooking::STATUS_CONFIRMED,
                    'penalty_if_cancelled' => round((float) $booking->vendor_expected_payout * 0.10, 2),
                    'notes' => $booking->notes,
                    'created_at' => $booking->created_at,
                ];
            });

        return response()->json([
            'bookings' => $bookings,
            'total' => $bookings->count(),
        ], 200);
    }

    /**
     * POST /api/professional/bookings/{booking_id}/cancel-within-window
     */
    public function cancelWithinWindow(Request $request, $id)
    {
        $user = $request->user();

        if (!$user->hasRole('provider')) {
            return response()->json(['message' => 'Forbidden. You are not a professional provider.'], 403);
        }

        try {
            $result = DB::transaction(function () use ($id, $user) {
                $booking = ServiceBooking::where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    return ['success' => false, 'message' => 'Booking not found.', 'code' => 404];
                }

                if ($booking->assigned_provider_user_id !== $user->id) {
                    return ['success' => false, 'message' => 'Forbidden. Booking not assigned to you.', 'code' => 403];
                }

                if ($booking->status !== ServiceBooking::STATUS_VENDOR_ACCEPTED) {
                    return ['success' => false, 'message' => 'Booking is not in vendor_accepted status.', 'code' => 422];
                }

                if (!$booking->action_window_ends_at || now()->gt($booking->action_window_ends_at)) {
                    return ['success' => false, 'message' => 'Action window has expired.', 'code' => 422];
                }

                $now = now();

                // Mark current vendor's offer as cancelled
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->where('provider_user_id', $user->id)
                    ->update([
                        'status' => BookingVendorOffer::STATUS_CANCELLED,
                    ]);

                // Reactivate offers for other vendors
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->where('provider_user_id', '!=', $user->id)
                    ->update([
                        'status' => BookingVendorOffer::STATUS_SENT,
                        'rejected_at' => null,
                    ]);

                // Update booking
                $booking->update([
                    'assigned_provider_user_id' => null,
                    'status' => ServiceBooking::STATUS_BROADCASTED,
                    'vendor_window_cancelled_at' => $now,
                    'vendor_accepted_at' => null,
                    'action_window_ends_at' => null,
                    'confirmed_at' => null,
                ]);

                // Run matching service to find any additional matching vendors
                try {
                    $matchingService = new \App\Services\VendorMatchingService();
                    $matchingService->broadcastToMatchingVendors($booking);
                } catch (\Exception $matchingEx) {
                    \Illuminate\Support\Facades\Log::warning("Failed to run vendor matching service after within-window cancel: " . $matchingEx->getMessage());
                }

                return ['success' => true, 'booking' => $booking->fresh()];
            });

            if (!$result['success']) {
                return response()->json(['message' => $result['message']], $result['code']);
            }

            return response()->json([
                'message' => 'Booking cancelled within action window. No penalty applied.',
                'booking' => $result['booking']
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while cancelling booking.'], 500);
        }
    }

    /**
     * POST /api/professional/bookings/{booking_id}/request-change-within-window
     */
    public function requestChangeWithinWindow(Request $request, $id)
    {
        $user = $request->user();

        if (!$user->hasRole('provider')) {
            return response()->json(['message' => 'Forbidden. You are not a professional provider.'], 403);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($id, $user, $validated) {
                $booking = ServiceBooking::where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    return ['success' => false, 'message' => 'Booking not found.', 'code' => 404];
                }

                if ($booking->assigned_provider_user_id !== $user->id) {
                    return ['success' => false, 'message' => 'Forbidden. Booking not assigned to you.', 'code' => 403];
                }

                if ($booking->status !== ServiceBooking::STATUS_VENDOR_ACCEPTED) {
                    return ['success' => false, 'message' => 'Booking is not in vendor_accepted status.', 'code' => 422];
                }

                if (!$booking->action_window_ends_at || now()->gt($booking->action_window_ends_at)) {
                    return ['success' => false, 'message' => 'Action window has expired.', 'code' => 422];
                }

                $booking->update([
                    'status' => ServiceBooking::STATUS_CHANGE_REQUESTED,
                    'vendor_change_requested_at' => now(),
                    'vendor_change_reason' => $validated['reason'],
                ]);

                return ['success' => true, 'booking' => $booking->fresh()];
            });

            if (!$result['success']) {
                return response()->json(['message' => $result['message']], $result['code']);
            }

            return response()->json([
                'message' => 'Change requested successfully.',
                'booking' => $result['booking']
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while requesting change.'], 500);
        }
    }

    /**
     * POST /api/professional/bookings/{booking_id}/cancel-after-confirmation
     *
     * Cancel booking after 5-minute action window (status = confirmed) with 10% penalty.
     */
    public function cancelAfterConfirmation(Request $request, $id)
    {
        $user = $request->user();

        // 1. Verify provider role
        if (!$user->hasRole('provider')) {
            return response()->json(['message' => 'Forbidden. You are not a professional provider.'], 403);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        try {
            $result = DB::transaction(function () use ($id, $user, $validated) {
                // 2. Load and lock booking
                $booking = ServiceBooking::where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    return [
                        'success' => false,
                        'message' => 'Booking not found.',
                        'code' => 404,
                    ];
                }

                // 3. Verify booking belongs to provider via assigned_provider_user_id
                if ($booking->assigned_provider_user_id !== $user->id) {
                    return [
                        'success' => false,
                        'message' => 'Forbidden. Booking not assigned to you.',
                        'code' => 403,
                    ];
                }

                // 4. Verify booking status is confirmed
                if ($booking->status !== ServiceBooking::STATUS_CONFIRMED) {
                    return [
                        'success' => false,
                        'message' => 'Only confirmed bookings can be cancelled using this endpoint. Current status: ' . $booking->status,
                        'code' => 422,
                    ];
                }

                // 5. Calculate 10% penalty of booking.vendor_expected_payout
                $expectedPayout = (float) $booking->vendor_expected_payout;
                $penaltyAmount = round($expectedPayout * 0.10, 2);

                // 6. Deduct penalty from provider wallet (Wallet CAN go negative)
                $walletService = new \App\Services\WalletService();
                $wallet = $walletService->chargePenalty(
                    $user->id,
                    $booking->id,
                    $penaltyAmount,
                    "10% penalty for vendor cancellation after confirmation"
                );

                // Mark current vendor's offer as cancelled
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->where('provider_user_id', $user->id)
                    ->update([
                        'status' => BookingVendorOffer::STATUS_CANCELLED,
                    ]);

                // Reactivate offers for other vendors
                BookingVendorOffer::where('booking_id', $booking->id)
                    ->where('provider_user_id', '!=', $user->id)
                    ->update([
                        'status' => BookingVendorOffer::STATUS_SENT,
                        'rejected_at' => null,
                    ]);

                // 7. Update booking
                $booking->update([
                    'status' => ServiceBooking::STATUS_BROADCASTED,
                    'assigned_provider_user_id' => null, // Reset assignment
                    'vendor_accepted_at' => null,
                    'action_window_ends_at' => null,
                    'confirmed_at' => null,
                    'vendor_window_cancelled_at' => now(),
                    'cancellation_reason' => $validated['reason'] ?? 'Cancelled by vendor after confirmation.',
                    'cancelled_at' => now(),
                ]);

                // Run matching service to find any additional matching vendors
                try {
                    $matchingService = new \App\Services\VendorMatchingService();
                    $matchingService->broadcastToMatchingVendors($booking);
                } catch (\Exception $matchingEx) {
                    \Illuminate\Support\Facades\Log::warning("Failed to run vendor matching service after confirmation cancel: " . $matchingEx->getMessage());
                }

                return [
                    'success' => true,
                    'penalty_amount' => $penaltyAmount,
                    'wallet_balance' => (float) $wallet->balance,
                    'booking_status' => ServiceBooking::STATUS_BROADCASTED,
                ];
            });

            if (!$result['success']) {
                return response()->json(['message' => $result['message']], $result['code']);
            }

            return response()->json([
                'message' => 'Booking cancelled after confirmation. 10% penalty deducted from your wallet.',
                'penalty_amount' => $result['penalty_amount'],
                'wallet_balance' => $result['wallet_balance'],
                'booking_status' => $result['booking_status'],
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while cancelling the booking.'], 500);
        }
    }
}
