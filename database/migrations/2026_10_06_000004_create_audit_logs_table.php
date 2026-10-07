<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: the app only ever adds rows here. Nothing updates or deletes them.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete(); // kept even if the admin account is later removed
            $table->string('action', 150);            // the route, e.g. "POST api/v1/admin/kyc/{submission}/approve"
            $table->string('method', 10);
            $table->string('path', 255);              // what was actually called, e.g. "api/v1/admin/kyc/12/approve"
            $table->json('route_params')->nullable(); // which records it touched, e.g. {"submission": "12"}
            $table->json('input')->nullable();        // what the admin sent (reasons, notes), with secrets removed
            $table->unsignedSmallInteger('status');   // the HTTP status that came back
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};