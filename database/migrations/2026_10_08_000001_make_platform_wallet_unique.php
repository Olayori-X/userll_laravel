<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Refuse to guess if duplicates already exist: merge them by hand first.
        if (DB::table('wallets')->where('type', 'platform')->count() > 1) {
            throw new RuntimeException('More than one platform wallet exists. Merge them before running this migration.');
        }

        // Seller wallets leave this NULL (databases allow many NULLs in a unique index).
        // The platform wallet is the only row that sets it, so a second one cannot exist.
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('singleton_key', 20)->nullable()->unique()->after('type');
        });

        if (DB::table('wallets')->where('type', 'platform')->exists()) {
            DB::table('wallets')->where('type', 'platform')->update(['singleton_key' => 'platform']);
        } else {
            DB::table('wallets')->insert([
                'type' => 'platform',
                'singleton_key' => 'platform',
                'pending_balance' => 0,
                'available_balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropUnique(['singleton_key']);
            $table->dropColumn('singleton_key');
        });
    }
};