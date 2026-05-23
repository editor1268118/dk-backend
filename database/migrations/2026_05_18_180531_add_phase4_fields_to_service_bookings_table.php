<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('action_window_ends_at');
            }
            if (!Schema::hasColumn('service_bookings', 'vendor_window_cancelled_at')) {
                $table->timestamp('vendor_window_cancelled_at')->nullable()->after('confirmed_at');
            }
            if (!Schema::hasColumn('service_bookings', 'vendor_change_requested_at')) {
                $table->timestamp('vendor_change_requested_at')->nullable()->after('vendor_window_cancelled_at');
            }
            if (!Schema::hasColumn('service_bookings', 'vendor_change_reason')) {
                $table->text('vendor_change_reason')->nullable()->after('vendor_change_requested_at');
            }
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
            $columns = ['confirmed_at', 'vendor_window_cancelled_at', 'vendor_change_requested_at', 'vendor_change_reason'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
