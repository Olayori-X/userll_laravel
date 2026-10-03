<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('store_name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('state');
            $table->string('city');
            $table->string('kyc_status', 20)->default('none');
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bank_code', 20);
            $table->string('bank_name')->nullable();
            $table->string('account_number', 20);
            $table->string('account_name');
            $table->string('paystack_recipient_code')->nullable()->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50)->nullable();
            $table->string('recipient_name');
            $table->string('phone', 20);
            $table->string('street');
            $table->string('city');
            $table->string('state');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('payout_accounts');
        Schema::dropIfExists('seller_profiles');
    }
};
