<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete(); // set for a pre-sale enquiry
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete(); // set for an order chat: one per order
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['buyer_id', 'seller_id', 'listing_id']); // one enquiry per buyer, seller and listing
            $table->index(['buyer_id', 'last_message_at']);
            $table->index(['seller_id', 'last_message_at']);
        });

        // Nothing in the app has ever written to messages (there was no endpoint), so it should be empty.
        // Refuse to continue if it is not, rather than silently deleting someone's data.
        if (DB::table('messages')->exists()) {
            throw new RuntimeException('The messages table has rows. Check them before running this migration.');
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('listing_id'); // the context now lives on the conversation
            $table->dropConstrainedForeignId('order_id');
            $table->foreignId('conversation_id')->after('id')->constrained()->cascadeOnDelete();
            $table->index(['conversation_id', 'id']); // "this conversation's messages, in order"
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropConstrainedForeignId('conversation_id');
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('conversations');
    }
};