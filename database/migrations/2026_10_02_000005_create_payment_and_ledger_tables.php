<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->restrictOnDelete();
            $table->string('paystack_reference')->unique();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        // One wallet per seller, plus a single platform wallet (user_id null).
        // pending_balance = escrow held; available_balance = releasable to payout.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('type', 20)->default('seller');
            $table->bigInteger('pending_balance')->default(0);
            $table->bigInteger('available_balance')->default(0);
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('pending');
            $table->string('paystack_reference')->unique();
            $table->string('paystack_transfer_code')->nullable()->unique();
            $table->string('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Append-only. Rows are never updated or deleted; corrections are new rows.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('direction', 10);
            $table->string('bucket', 20);
            $table->unsignedBigInteger('amount');
            $table->bigInteger('balance_after');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index('order_id');
        });

        // Paystack does not send a unique event id, so event_key is built by us
        // (e.g. "charge.success:<reference>") and is unique to block double processing.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30)->default('paystack');
            $table->string('event_key');
            $table->string('event_type', 60);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('payments');
    }
};
