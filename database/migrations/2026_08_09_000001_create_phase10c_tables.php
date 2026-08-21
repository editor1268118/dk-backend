<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10C: Create all new tables for coupons, returns, refunds, reviews, and order status history.
     */
    public function up(): void
    {
        // ── Coupons ──
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('discount_type', 30); // percentage, fixed_amount, free_shipping
            $table->decimal('discount_value', 12, 2);
            $table->decimal('min_order_amount', 12, 2)->default(0);
            $table->decimal('max_discount_amount', 12, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('used_count')->default(0);
            $table->integer('per_user_limit')->nullable()->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('is_active');
            $table->index('discount_type');
        });

        // ── Coupon Usages ──
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('shop_order_id')->nullable()->constrained('shop_orders')->onDelete('set null');
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'user_id']);
        });

        // ── Shop Return Requests ──
        Schema::create('shop_return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('return_number', 50)->unique();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('status', 30)->default('requested'); // requested, approved, rejected, returned, cancelled
            $table->text('reason');
            $table->text('customer_note')->nullable();
            $table->text('admin_note')->nullable();
            $table->decimal('refund_amount_requested', 12, 2)->default(0);
            $table->decimal('refund_amount_approved', 12, 2)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('status');
            $table->index(['shop_order_id', 'status']);
        });

        // ── Shop Return Items ──
        Schema::create('shop_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_return_request_id')->constrained('shop_return_requests')->onDelete('cascade');
            $table->foreignId('shop_order_item_id')->constrained('shop_order_items')->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained('products')->onDelete('set null');
            $table->integer('quantity');
            $table->text('reason')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
        });

        // ── Shop Refunds ──
        Schema::create('shop_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_number', 50)->unique();
            $table->foreignId('shop_order_id')->constrained('shop_orders')->onDelete('cascade');
            $table->foreignId('shop_return_request_id')->nullable()->constrained('shop_return_requests')->onDelete('set null');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->string('status', 30)->default('pending'); // pending, approved, processed, rejected
            $table->string('refund_method')->nullable();
            $table->string('refund_reference')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index('status');
            $table->index('shop_order_id');
        });

        // ── Product Reviews ──
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('shop_order_id')->constrained('shop_orders')->onDelete('cascade');
            $table->foreignId('shop_order_item_id')->nullable()->constrained('shop_order_items')->onDelete('set null');
            $table->tinyInteger('rating'); // 1-5
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->boolean('verified_purchase')->default(true);
            $table->timestamps();

            // One review per user + product + order
            $table->unique(['user_id', 'product_id', 'shop_order_id'], 'unique_user_product_order_review');
            $table->index(['product_id', 'is_visible']);
        });

        // ── Shop Order Status Histories ──
        if (!Schema::hasTable('shop_order_status_histories')) {
            Schema::create('shop_order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_order_id')->constrained('shop_orders')->onDelete('cascade');
                $table->string('old_status', 30)->nullable();
                $table->string('new_status', 30);
                $table->text('note')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_role', 30)->nullable(); // admin, customer, system
                $table->timestamps();

                $table->index('shop_order_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_order_status_histories');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('shop_refunds');
        Schema::dropIfExists('shop_return_items');
        Schema::dropIfExists('shop_return_requests');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
    }
};
