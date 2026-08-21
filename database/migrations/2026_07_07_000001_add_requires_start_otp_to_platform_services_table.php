<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 6: Add service-level OTP requirement setting.
     * Some services require OTP start verification, some may not.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('platform_services', function (Blueprint $table) {
            if (!Schema::hasColumn('platform_services', 'requires_start_otp')) {
                $table->boolean('requires_start_otp')->default(true)->after('is_active');
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
        Schema::table('platform_services', function (Blueprint $table) {
            if (Schema::hasColumn('platform_services', 'requires_start_otp')) {
                $table->dropColumn('requires_start_otp');
            }
        });
    }
};
