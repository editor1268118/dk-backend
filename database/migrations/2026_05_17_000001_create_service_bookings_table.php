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
        Schema::create('service_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('platform_service_id')->constrained('platform_services')->cascadeOnDelete();
            $table->foreignId('assigned_provider_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Customer contact info (snapshot at booking time)
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();

            // Service location
            $table->string('service_country')->nullable();
            $table->string('service_state')->nullable();
            $table->string('service_city')->nullable();
            $table->string('service_pincode', 50)->nullable();
            $table->string('service_address_line_1')->nullable();
            $table->string('service_address_line_2')->nullable();

            // Scheduling
            $table->date('preferred_date');
            $table->string('preferred_time', 100);

            // Price snapshot (locked at booking time)
            $table->decimal('customer_price', 12, 2);
            $table->decimal('vendor_payout_percentage', 5, 2);
            $table->decimal('vendor_expected_payout', 12, 2);
            $table->decimal('platform_amount', 12, 2);

            // Payment & status
            $table->string('payment_status')->default('pending');
            $table->string('status')->default('job_created');

            // Misc
            $table->text('notes')->nullable();
            $table->string('cancellation_rule_version')->nullable();

            // Vendor acceptance timestamp
            $table->timestamp('vendor_accepted_at')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index('status');
            $table->index('payment_status');
            $table->index(['service_city', 'service_state']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_bookings');
    }
};
