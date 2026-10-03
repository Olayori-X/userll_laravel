<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->unique(['cart_id', 'listing_id']);
        });

        // One checkout = one payment from the buyer. It can spawn several orders
        // (one per seller), which is why payments hang off checkouts, not orders.
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('total');
            $table->string('status', 20)->default('pending');
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('checkout_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30)->default('pending_payment');

            // Address is copied in so later edits never change past orders.
            $table->string('shipping_name');
            $table->string('shipping_phone', 20);
            $table->string('shipping_street');
            $table->string('shipping_city');
            $table->string('shipping_state');

            // All amounts in kobo. total = what the buyer pays;
            // seller receives (subtotal - commission) once the order completes.
            $table->unsignedBigInteger('subtotal');
            $table->unsignedSmallInteger('commission_rate_bps');
            $table->unsignedBigInteger('commission');
            $table->unsignedBigInteger('total');

            $table->text('tracking_info')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('ship_by_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_release_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
            $table->index(['seller_id', 'status']);
            $table->index(['status', 'ship_by_at']);
            $table->index(['status', 'auto_release_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
