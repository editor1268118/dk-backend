<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SystemBookingController extends Controller
{
    /**
     * POST /api/system/bookings/auto-confirm-expired-windows
     *
     * Auto-confirm bookings where the 5-minute action window has expired.
     * Phase 6: Also snapshots otp_required and generates OTP if needed.
     */
    public function autoConfirmExpiredWindows(Request $request)
    {
        // Protect with auth:sanctum and check for admin role if applicable.
        // For development/admin testing, we verify user is authenticated.
        if ($request->user() && !$request->user()->hasRole('admin')) {
            // If not admin, return 403
            return response()->json([
                'message' => 'Forbidden. Admin access required to trigger system auto-confirm.',
                'note' => 'In production, this is executed automatically via scheduler/cron.'
            ], 403);
        }

        $now = now();

        $expiredBookings = ServiceBooking::with('platformService')
            ->where('status', ServiceBooking::STATUS_VENDOR_ACCEPTED)
            ->whereNotNull('assigned_provider_user_id')
            ->whereNotNull('action_window_ends_at')
            ->where('action_window_ends_at', '<=', $now)
            ->get();

        $confirmedReferences = [];

        foreach ($expiredBookings as $booking) {
            // Phase 6: Snapshot otp_required from platform service
            $otpRequired = $booking->platformService
                ? (bool) $booking->platformService->requires_start_otp
                : true; // Default to true if service not found

            $updateData = [
                'status' => ServiceBooking::STATUS_CONFIRMED,
                'confirmed_at' => $now,
                'otp_required' => $otpRequired,
            ];

            // Phase 6: Auto-generate OTP if required
            if ($otpRequired) {
                $updateData['start_otp'] = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $updateData['start_otp_generated_at'] = $now;
            }

            $booking->update($updateData);
            $confirmedReferences[] = $booking->booking_reference;

            // Phase 11: Notify about confirmation + OTP availability
            try {
                $notificationService = app(\App\Services\NotificationService::class);
                $notifOptions = [
                    'entity_type' => 'service_booking',
                    'entity_id'   => $booking->id,
                    'data'        => ['booking_reference' => $booking->booking_reference],
                ];

                // Notify customer: booking confirmed
                $notificationService->notifyUser(
                    $booking->user_id,
                    'Booking confirmed',
                    "Your booking #{$booking->booking_reference} has been confirmed.",
                    \App\Models\Notification::TYPE_SERVICE_BOOKING_CONFIRMED,
                    array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
                );

                // Notify professional: booking confirmed
                if ($booking->assigned_provider_user_id) {
                    $notificationService->notifyProvider(
                        $booking->assigned_provider_user_id,
                        'Booking confirmed',
                        "Booking #{$booking->booking_reference} has been confirmed. Please be ready for the service.",
                        \App\Models\Notification::TYPE_SERVICE_BOOKING_CONFIRMED,
                        array_merge($notifOptions, ['action_url' => '/dashboard?tab=bookings-received'])
                    );
                }

                // OTP available notification (customer only, no raw OTP)
                if ($otpRequired) {
                    $notificationService->notifyUser(
                        $booking->user_id,
                        'Start OTP available',
                        'Your service start OTP is now available. Open booking detail to view it.',
                        \App\Models\Notification::TYPE_SERVICE_OTP_AVAILABLE,
                        array_merge($notifOptions, ['action_url' => '/dashboard?tab=service-booked'])
                    );
                }
            } catch (\Exception $e) {
                // Never fail core action
            }
        }

        return response()->json([
            'message' => 'Auto-confirmation check completed.',
            'confirmed_count' => count($confirmedReferences),
            'confirmed_booking_references' => $confirmedReferences,
        ], 200);
    }

    /**
     * POST /api/system/bookings/mark-no-start-failures
     *
     * Find confirmed bookings where the vendor has not started job
     * within 1 hour of scheduled preferred date/time.
     *
     * Phase 6: Marks as failed_no_start.
     * TODO: Phase 6B/Phase 7 can apply ₹20 no-start penalty.
     */
    public function markNoStartFailures(Request $request)
    {
        // Protect with auth:sanctum and check for admin role
        if ($request->user() && !$request->user()->hasRole('admin')) {
            return response()->json([
                'message' => 'Forbidden. Admin access required to trigger no-start failure check.',
                'note' => 'In production, this should be executed via scheduler/cron.',
            ], 403);
        }

        $now = now();

        // Find confirmed bookings where vendor has not started job
        // and preferred_date + preferred_time + 1 hour <= now
        $failedBookings = ServiceBooking::where('status', ServiceBooking::STATUS_CONFIRMED)
            ->whereNull('job_started_at')
            ->get()
            ->filter(function ($booking) use ($now) {
                // Parse preferred_date + preferred_time into a Carbon datetime
                try {
                    $scheduledTime = Carbon::parse(
                        $booking->preferred_date->format('Y-m-d') . ' ' . $booking->preferred_time
                    );
                    // Check if 1 hour has passed since the scheduled time
                    return $scheduledTime->copy()->addHour()->lte($now);
                } catch (\Exception $e) {
                    // If time parsing fails, skip this booking
                    return false;
                }
            });

        $markedReferences = [];

        foreach ($failedBookings as $booking) {
            $booking->update([
                'status' => ServiceBooking::STATUS_FAILED_NO_START,
                'no_start_marked_at' => $now,
            ]);
            $markedReferences[] = $booking->booking_reference;

            // TODO: Phase 6B/Phase 7 can apply ₹20 no-start penalty to vendor wallet here.
        }

        return response()->json([
            'message' => 'No-start failure check completed.',
            'marked_count' => count($markedReferences),
            'marked_booking_references' => $markedReferences,
            'note' => 'No-start penalty (₹20) will be implemented in Phase 6B/Phase 7.',
        ], 200);
    }
}
