<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10C: Add coupon and return/refund columns to existing carts and shop_orders tables.
     */
    public function up(): void
    {
        // ── Add coupon fields to carts ──
        Schema::table('carts', function (Blueprint $table) {
            if (!Schema::hasColumn('carts', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('status')->constrained('coupons')->onDelete('set null');
            }
            if (!Schema::hasColumn('carts', 'coupon_code')) {
                $table->string('coupon_code', 50)->nullable()->after('coupon_id');
            }
            if (!Schema::hasColumn('carts', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->default(0)->after('coupon_code');
            }
        });

        // ── Add coupon + return/refund fields to shop_orders ──
        Schema::table('shop_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_orders', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('cancelled_at')->constrained('coupons')->onDelete('set null');
            }
            if (!Schema::hasColumn('shop_orders', 'coupon_code')) {
                $table->string('coupon_code', 50)->nullable()->after('coupon_id');
            }
            if (!Schema::hasColumn('shop_orders', 'refund_status')) {
                $table->string('refund_status', 30)->nullable()->after('coupon_code');
            }
            if (!Schema::hasColumn('shop_orders', 'return_status')) {
                $table->string('return_status', 30)->nullable()->after('refund_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            if (Schema::hasColumn('shop_orders', 'coupon_id')) {
                $table->dropForeign(['coupon_id']);
                $table->dropColumn('coupon_id');
            }
            if (Schema::hasColumn('shop_orders', 'coupon_code')) {
                $table->dropColumn('coupon_code');
            }
            if (Schema::hasColumn('shop_orders', 'refund_status')) {
                $table->dropColumn('refund_status');
            }
            if (Schema::hasColumn('shop_orders', 'return_status')) {
                $table->dropColumn('return_status');
            }
        });

        Schema::table('carts', function (Blueprint $table) {
            if (Schema::hasColumn('carts', 'coupon_id')) {
                $table->dropForeign(['coupon_id']);
                $table->dropColumn('coupon_id');
            }
            if (Schema::hasColumn('carts', 'coupon_code')) {
                $table->dropColumn('coupon_code');
            }
            if (Schema::hasColumn('carts', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
        });
    }
};
