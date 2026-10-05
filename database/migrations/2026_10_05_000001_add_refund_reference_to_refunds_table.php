<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            // Paystack's own reference for a refund, learned from its webhooks.
            // Once set, later events for this refund find exactly this row.
            $table->string('paystack_refund_reference')->nullable()->after('paystack_refund_id');
            $table->index(['payment_id', 'paystack_refund_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['payment_id', 'paystack_refund_reference']);
            $table->dropColumn('paystack_refund_reference');
        });
    }
};