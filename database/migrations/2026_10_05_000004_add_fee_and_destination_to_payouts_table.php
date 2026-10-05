<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            // "amount" is what leaves the seller's wallet. The seller receives amount - fee.
            $table->unsignedBigInteger('fee')->default(0)->after('amount');

            // Where this payout was sent, copied from the seller's payout account at the moment they asked.
            // If they change their bank account later, this payout still goes where they requested.
            $table->string('recipient_code')->nullable()->after('paystack_transfer_code');
            $table->string('bank_name')->nullable()->after('recipient_code');
            $table->string('account_name')->nullable()->after('bank_name');
            $table->string('account_last4', 4)->nullable()->after('account_name');

            $table->index(['status', 'updated_at']); // the reconcile command looks for payouts nobody confirmed
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at']);
            $table->dropIndex(['seller_id', 'status']);
            $table->dropColumn(['fee', 'recipient_code', 'bank_name', 'account_name', 'account_last4']);
        });
    }
};