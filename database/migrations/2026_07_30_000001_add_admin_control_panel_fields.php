<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 9: Admin Control Panel — Schema additions.
     *
     * Adds:
     * - users: is_blocked, blocked_at, blocked_reason
     * - provider_profiles: is_suspended, suspended_at, suspension_reason
     * - admin_action_logs: old_values, new_values
     * - service_bookings: refund_status, refund_note
     * - service_categories: is_active (if missing)
     */
    public function up()
    {
        // Users: blocking fields
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_blocked')->default(false)->after('status');
            $table->timestamp('blocked_at')->nullable()->after('is_blocked');
            $table->text('blocked_reason')->nullable()->after('blocked_at');
        });

        // Provider Profiles: suspension fields
        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->boolean('is_suspended')->default(false)->after('verification_status');
            $table->timestamp('suspended_at')->nullable()->after('is_suspended');
            $table->text('suspension_reason')->nullable()->after('suspended_at');
        });

        // Admin Action Logs: old_values, new_values for audit trail
        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->text('old_values')->nullable()->after('description');
            $table->text('new_values')->nullable()->after('old_values');
        });

        // Service Bookings: refund ledger fields
        Schema::table('service_bookings', function (Blueprint $table) {
            $table->string('refund_status')->nullable()->after('refund_amount');
            $table->text('refund_note')->nullable()->after('refund_status');
        });

        // Service Categories: ensure is_active column exists
        if (!Schema::hasColumn('service_categories', 'is_active')) {
            Schema::table('service_categories', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('description');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_blocked', 'blocked_at', 'blocked_reason']);
        });

        Schema::table('provider_profiles', function (Blueprint $table) {
            $table->dropColumn(['is_suspended', 'suspended_at', 'suspension_reason']);
        });

        Schema::table('admin_action_logs', function (Blueprint $table) {
            $table->dropColumn(['old_values', 'new_values']);
        });

        Schema::table('service_bookings', function (Blueprint $table) {
            $table->dropColumn(['refund_status', 'refund_note']);
        });
    }
};
