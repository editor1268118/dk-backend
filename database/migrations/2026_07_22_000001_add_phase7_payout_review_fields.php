<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 7: Completion-Based Payout Release + Optional Customer Review.
     *
     * Adds payout tracking fields to service_bookings:
     * - payout_status: pending | released | on_hold
     * - payout_released_at: when payout was released
     * - payout_release_reference: unique reference for the payout transaction
     * - customer_review_submitted_at: when customer submitted a review
     *
     * Creates booking_reviews table for optional customer reviews.
     *
     * Migrates any existing review_pending records to completed status.
     *
     * @return void
     */
    public function up()
    {
        // 1. Add payout + review tracking fields to service_bookings
        Schema::table('service_bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('service_bookings', 'payout_status')) {
                $table->string('payout_status')->default('pending')->after('status');
            }
            if (!Schema::hasColumn('service_bookings', 'payout_released_at')) {
                $table->timestamp('payout_released_at')->nullable()->after('payout_status');
            }
            if (!Schema::hasColumn('service_bookings', 'payout_release_reference')) {
                $table->string('payout_release_reference')->nullable()->after('payout_released_at');
            }
            if (!Schema::hasColumn('service_bookings', 'customer_review_submitted_at')) {
                $table->timestamp('customer_review_submitted_at')->nullable()->after('payout_release_reference');
            }
        });

        // 2. Create booking_reviews table
        if (!Schema::hasTable('booking_reviews')) {
            Schema::create('booking_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->unique()->constrained('service_bookings')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('provider_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->tinyInteger('rating'); // 1–5
                $table->text('comment')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->timestamps();

                $table->index('provider_user_id');
                $table->index('rating');
            });
        }

        // 3. Migrate any existing review_pending records to completed
        // These were set by Phase 6 completeJob(). Now we treat them as completed.
        DB::table('service_bookings')
            ->where('status', 'review_pending')
            ->update([
                'status' => 'completed',
                'payout_status' => 'pending', // Will need manual payout or re-run
            ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('booking_reviews');

        Schema::table('service_bookings', function (Blueprint $table) {
            $columns = [
                'payout_status',
                'payout_released_at',
                'payout_release_reference',
                'customer_review_submitted_at',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('service_bookings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
