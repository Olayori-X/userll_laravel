<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            // Which filesystem disk holds this submission's ID photo, so files stay readable
            // after the app moves from local storage to Cloudflare.
            $table->string('disk', 20)->default('local')->after('id_photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};