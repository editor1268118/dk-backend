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
            // Cancellation fields (cancellation_reason and cancelled_at exist from Phase 5)
            if (!Schema::hasColumn('service_bookings', 'cancelled_by')) {
                $table->string('cancelled_by')->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('service_bookings', 'cancellation_fee')) {
                $table->decimal('cancellation_fee', 12, 2)->default(0)->after('cancelled_by');
            }
            if (!Schema::hasColumn('service_bookings', 'refund_amount')) {
                $table->decimal('refund_amount', 12, 2)->default(0)->after('cancellation_fee');
            }
            if (!Schema::hasColumn('service_bookings', 'cancellation_policy_snapshot')) {
                $table->text('cancellation_policy_snapshot')->nullable()->after('refund_amount');
            }

            // Reschedule fields
            if (!Schema::hasColumn('service_bookings', 'reschedule_count')) {
                $table->integer('reschedule_count')->default(0)->after('cancellation_policy_snapshot');
            }
            if (!Schema::hasColumn('service_bookings', 'last_rescheduled_at')) {
                $table->timestamp('last_rescheduled_at')->nullable()->after('reschedule_count');
            }
            if (!Schema::hasColumn('service_bookings', 'original_preferred_date')) {
                $table->date('original_preferred_date')->nullable()->after('last_rescheduled_at');
            }
            if (!Schema::hasColumn('service_bookings', 'original_preferred_time')) {
                $table->string('original_preferred_time', 100)->nullable()->after('original_preferred_date');
            }

            // Issue reporting fields
            if (!Schema::hasColumn('service_bookings', 'issue_reported_at')) {
                $table->timestamp('issue_reported_at')->nullable()->after('customer_review_submitted_at');
            }
            if (!Schema::hasColumn('service_bookings', 'issue_status')) {
                $table->string('issue_status')->nullable()->after('issue_reported_at');
            }
            if (!Schema::hasColumn('service_bookings', 'issue_description')) {
                $table->text('issue_description')->nullable()->after('issue_status');
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
                'cancelled_by',
                'cancellation_fee',
                'refund_amount',
                'cancellation_policy_snapshot',
                'reschedule_count',
                'last_rescheduled_at',
                'original_preferred_date',
                'original_preferred_time',
                'issue_reported_at',
                'issue_status',
                'issue_description',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
