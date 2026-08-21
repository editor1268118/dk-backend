<?php

namespace App\Services;

use App\Models\ServiceBooking;
use Carbon\Carbon;

class CancellationPolicyService
{
    /**
     * Calculate cancellation fee and preview data for a booking.
     *
     * @param ServiceBooking $booking
     * @return array
     */
    public function calculateFee(ServiceBooking $booking): array
    {
        $canCancel = $booking->canCustomerCancel();
        $reason = null;

        if (!$canCancel) {
            $reason = 'Cancellation not allowed in current status: ' . $booking->status;
        }

        $serviceDateTime = $booking->getServiceDateTime();
        $now = now();
        
        $hoursRemaining = 0;
        if ($serviceDateTime && $serviceDateTime->isFuture()) {
            $hoursRemaining = $now->floatDiffInHours($serviceDateTime, false);
        }

        if ($hoursRemaining < 0 || !$canCancel) {
            $feePercent = 0;
            $fee = 0.0;
            $refund = 0.0;
            $label = 'Cancellation not allowed';
        } elseif ($hoursRemaining > 24) {
            $feePercent = 0;
            $fee = 0.0;
            $refund = (float) $booking->customer_price;
            $label = 'More than 24 hours before service (Full Refund)';
        } elseif ($hoursRemaining > 12) {
            $feePercent = 50;
            $fee = round((float) $booking->customer_price * 0.50, 2);
            $refund = round((float) $booking->customer_price - $fee, 2);
            $label = '24 to 12 hours before service (50% Penalty)';
        } elseif ($hoursRemaining > 3) {
            $feePercent = 80;
            $fee = round((float) $booking->customer_price * 0.80, 2);
            $refund = round((float) $booking->customer_price - $fee, 2);
            $label = '12 to 3 hours before service (80% Penalty)';
        } else {
            $feePercent = 90;
            $fee = round((float) $booking->customer_price * 0.90, 2);
            $refund = round((float) $booking->customer_price - $fee, 2);
            $label = 'Less than 3 hours before service (90% Penalty)';
        }

        return [
            'can_cancel' => $canCancel,
            'reason' => $reason,
            'hours_remaining' => round($hoursRemaining, 2),
            'cancellation_fee_percent' => $feePercent,
            'cancellation_fee' => $fee,
            'refund_amount' => $refund,
            'customer_price' => (float) $booking->customer_price,
            'policy_label' => $label,
        ];
    }

    /**
     * Get preview details for cancellation API.
     *
     * @param ServiceBooking $booking
     * @return array
     */
    public function getPreviewForBooking(ServiceBooking $booking): array
    {
        $policy = $this->calculateFee($booking);

        return [
            'booking_reference' => $booking->booking_reference,
            'customer_price' => $policy['customer_price'],
            'preferred_date' => $booking->preferred_date ? $booking->preferred_date->format('Y-m-d') : null,
            'preferred_time' => $booking->preferred_time,
            'status' => $booking->status,
            'can_cancel' => $policy['can_cancel'],
            'reason' => $policy['reason'],
            'cancellation_fee_percent' => $policy['cancellation_fee_percent'],
            'cancellation_fee' => $policy['cancellation_fee'],
            'refund_amount' => $policy['refund_amount'],
            'policy_label' => $policy['policy_label'],
            'hours_remaining_calculated' => $policy['hours_remaining'],
        ];
    }
}
