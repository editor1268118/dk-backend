<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 9: Admin Support Dashboard — Admin Action Log table.
     * Tracks all admin support actions such as resolving/rejecting issues,
     * expiring pending reschedules, and any manual interventions.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('admin_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('action');          // e.g. issue_resolved, issue_rejected, reschedule_expired
            $table->string('entity_type');     // e.g. service_booking, professional_wallet
            $table->unsignedBigInteger('entity_id');
            $table->text('description')->nullable();
            $table->text('metadata')->nullable(); // JSON-encoded extra context
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index('admin_user_id');
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('admin_action_logs');
    }
};
