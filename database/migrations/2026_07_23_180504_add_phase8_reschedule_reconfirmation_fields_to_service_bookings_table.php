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
            $table->foreignId('previous_assigned_provider_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reschedule_reconfirmation_deadline_at')->nullable();
            $table->timestamp('reschedule_reconfirmed_at')->nullable();
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
            $table->dropForeign(['previous_assigned_provider_user_id']);
            $table->dropColumn([
                'previous_assigned_provider_user_id',
                'reschedule_reconfirmation_deadline_at',
                'reschedule_reconfirmed_at'
            ]);
        });
    }
};
