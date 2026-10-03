<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kobo, like every other money column. 0 = free delivery.
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_fee')->default(0)->after('price');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_fee')->default(0)->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
        Schema::table('listings', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
    }
};
