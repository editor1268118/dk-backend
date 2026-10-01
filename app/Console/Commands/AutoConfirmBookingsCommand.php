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
        // Use the same lifecycle as the API, including OTP generation and notifications.
        $request = new \Illuminate\Http\Request();
        $response = app(\App\Http\Controllers\Api\SystemBookingController::class)
            ->autoConfirmExpiredWindows($request);
        $this->info($response->getData(true)['message'] ?? 'Expired action windows processed.');
        return Command::SUCCESS;
    }
}
