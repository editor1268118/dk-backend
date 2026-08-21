<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 6: Add OTP verification and job lifecycle fields to service_bookings.
     *
     * - start_otp: 6-digit OTP shown to customer, entered by vendor to start job
     * - start_otp_generated_at: when OTP was generated
     * - start_otp_verified_at: when vendor successfully verified OTP
     * - job_started_at: when vendor started the job
     * - job_completed_at: when vendor marked job as completed
     * - no_start_marked_at: when system marked booking as failed_no_start
     * - otp_required: snapshotted from platform_services.requires_start_otp at confirmation
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'otp_required')) {
                $table->boolean('otp_required')->default(true)->after('status');
            }
            if (!Schema::hasColumn('service_bookings', 'start_otp')) {
                $table->string('start_otp', 10)->nullable()->after('otp_required');
            }
            if (!Schema::hasColumn('service_bookings', 'start_otp_generated_at')) {
                $table->timestamp('start_otp_generated_at')->nullable()->after('start_otp');
            }
            if (!Schema::hasColumn('service_bookings', 'start_otp_verified_at')) {
                $table->timestamp('start_otp_verified_at')->nullable()->after('start_otp_generated_at');
            }
            if (!Schema::hasColumn('service_bookings', 'job_started_at')) {
                $table->timestamp('job_started_at')->nullable()->after('start_otp_verified_at');
            }
            if (!Schema::hasColumn('service_bookings', 'job_completed_at')) {
                $table->timestamp('job_completed_at')->nullable()->after('job_started_at');
            }
            if (!Schema::hasColumn('service_bookings', 'no_start_marked_at')) {
                $table->timestamp('no_start_marked_at')->nullable()->after('job_completed_at');
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
            $columns = [
                'otp_required',
                'start_otp',
                'start_otp_generated_at',
                'start_otp_verified_at',
                'job_started_at',
                'job_completed_at',
                'no_start_marked_at',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
