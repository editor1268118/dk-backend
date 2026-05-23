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
        Schema::create('professional_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->string('currency')->default('INR');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('professional_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('wallet_id')
                ->constrained('professional_wallets')
                ->cascadeOnDelete();
            $table->foreignId('booking_id')
                ->nullable()
                ->constrained('service_bookings')
                ->nullOnDelete();
            $table->string('type'); // recharge, penalty_debit, payout_credit, adjustment
            $table->decimal('amount', 12, 2);
            $table->string('direction'); // credit, debit
            $table->text('description')->nullable();
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);
            $table->string('reference')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('professional_wallet_transactions');
        Schema::dropIfExists('professional_wallets');
    }
};
