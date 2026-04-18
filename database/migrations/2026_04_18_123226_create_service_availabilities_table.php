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
        Schema::create('service_availabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('provider_user_id');
            $table->date('available_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('max_bookings')->nullable();
            $table->boolean('is_available')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('service_id')->references('id')->on('services')->onDelete('cascade');
            $table->foreign('provider_user_id')->references('id')->on('users')->onDelete('cascade');

            // Optional: Index for common query patterns
            $table->index(['service_id', 'available_date']);
            $table->index(['provider_user_id', 'available_date']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('service_availabilities');
    }
};
