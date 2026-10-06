<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // The seller's one public reply.
            $table->text('seller_reply')->nullable()->after('comment');
            $table->timestamp('seller_replied_at')->nullable()->after('seller_reply');

            // Moderation: a hidden review leaves public lists and the seller's average.
            $table->boolean('is_hidden')->default(false)->after('seller_replied_at');
            $table->string('hidden_reason')->nullable()->after('is_hidden');
            $table->foreignId('hidden_by')->nullable()->after('hidden_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('hidden_at')->nullable()->after('hidden_by');

            // "A seller's visible reviews, newest first" is the main public query.
            $table->index(['seller_id', 'is_hidden', 'created_at']);
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            // Recomputed from the visible reviews by ReviewService, never edited by hand.
            $table->unsignedInteger('reviews_count')->default(0)->after('kyc_verified_at');
            $table->decimal('rating_average', 3, 2)->default(0)->after('reviews_count');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->dropColumn(['reviews_count', 'rating_average']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'is_hidden', 'created_at']);
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['seller_reply', 'seller_replied_at', 'is_hidden', 'hidden_reason', 'hidden_at']);
        });
    }
};