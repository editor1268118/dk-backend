<?php

namespace App\Console\Commands;

use App\Models\ServiceBooking;
use Illuminate\Console\Command;

class AutoConfirmBookingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:auto-confirm';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-confirm bookings where the 5-minute vendor action window has expired.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $now = now();

        $expiredBookings = ServiceBooking::where('status', ServiceBooking::STATUS_VENDOR_ACCEPTED)
            ->whereNotNull('assigned_provider_user_id')
            ->whereNotNull('action_window_ends_at')
            ->where('action_window_ends_at', '<=', $now)
            ->get();

        $count = 0;
        foreach ($expiredBookings as $booking) {
            $booking->update([
                'status' => ServiceBooking::STATUS_CONFIRMED,
                'confirmed_at' => $now,
            ]);
            $count++;
            $this->info("Confirmed booking: {$booking->booking_reference}");
        }

        $this->info("Auto-confirmation complete. Total confirmed: {$count}");

        return Command::SUCCESS;
    }
}
