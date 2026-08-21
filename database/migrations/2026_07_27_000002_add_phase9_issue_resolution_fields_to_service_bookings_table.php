<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 9: Admin Support Dashboard — Issue Resolution fields.
     * Adds admin-side fields for resolving or rejecting reported issues.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'issue_resolution_note')) {
                $table->text('issue_resolution_note')->nullable()->after('issue_description');
            }
            if (!Schema::hasColumn('service_bookings', 'issue_resolved_at')) {
                $table->timestamp('issue_resolved_at')->nullable()->after('issue_resolution_note');
            }
            if (!Schema::hasColumn('service_bookings', 'issue_resolved_by')) {
                $table->unsignedBigInteger('issue_resolved_by')->nullable()->after('issue_resolved_at');
                $table->foreign('issue_resolved_by')->references('id')->on('users')->nullOnDelete();
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
            $columns = ['issue_resolution_note', 'issue_resolved_at', 'issue_resolved_by'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
