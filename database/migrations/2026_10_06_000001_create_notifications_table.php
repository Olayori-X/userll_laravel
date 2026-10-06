<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's standard table for database notifications: one row per message in a user's inbox.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable'); // who it is for (always a user here)
            $table->text('data');         // the message: kind, title, body and where it links
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_id', 'read_at']); // "how many unread?" is asked on every page load
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};