<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11: In-App Notifications & Alerts
     *
     * Create the notifications table for database-driven in-app notifications.
     * No SMS, WhatsApp, email, browser push, or WebSocket.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('type');               // e.g. service_booking_created, shop_order_placed
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->string('entity_type')->nullable();     // e.g. service_booking, shop_order
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Performance indexes
            $table->index('type');
            $table->index('priority');
            $table->index('read_at');
            $table->index('created_at');
            $table->index(['user_id', 'read_at']);       // Unread count queries
            $table->index(['user_id', 'created_at']);    // Listing queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
