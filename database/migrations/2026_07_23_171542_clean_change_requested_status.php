<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CleanChangeRequestedStatus extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. Clean existing statuses
        // If assigned and previously confirmed -> confirmed
        DB::table('service_bookings')
            ->where('status', 'change_requested')
            ->whereNotNull('assigned_provider_user_id')
            ->whereNotNull('confirmed_at')
            ->update(['status' => 'confirmed']);

        // If assigned but not confirmed -> vendor_accepted
        DB::table('service_bookings')
            ->where('status', 'change_requested')
            ->whereNotNull('assigned_provider_user_id')
            ->whereNull('confirmed_at')
            ->update(['status' => 'vendor_accepted']);

        // Any remaining change_requested without assignment (edge case) -> broadcasted
        DB::table('service_bookings')
            ->where('status', 'change_requested')
            ->update(['status' => 'broadcasted']);

        // 2. Drop the unused columns
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dropColumn(['vendor_change_reason', 'vendor_change_requested_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->string('vendor_change_reason', 1000)->nullable();
            $table->timestamp('vendor_change_requested_at')->nullable();
        });
    }
}
