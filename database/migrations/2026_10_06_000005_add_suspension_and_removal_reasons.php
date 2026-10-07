<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When and why an admin suspended the account. Cleared on reactivation (the audit log keeps the history).
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });

        Schema::table('listings', function (Blueprint $table) {
            // Set when an admin removes a listing, so the seller can see why. Cleared when it is restored.
            $table->timestamp('removed_at')->nullable();
            $table->string('removal_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['removed_at', 'removal_reason']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};