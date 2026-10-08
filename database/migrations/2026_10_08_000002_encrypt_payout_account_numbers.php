<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An encrypted value is far longer than 20 characters, so the column must grow first.
        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->text('account_number')->change();
        });

        DB::table('payout_accounts')->orderBy('id')->each(function ($row) {
            try {
                Crypt::decryptString($row->account_number);

                return; // already encrypted (the migration was run before): leave it alone
            } catch (DecryptException) {
                // plaintext: encrypt it below
            }

            DB::table('payout_accounts')->where('id', $row->id)->update([
                'account_number' => Crypt::encryptString($row->account_number),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('payout_accounts')->orderBy('id')->each(function ($row) {
            try {
                $plain = Crypt::decryptString($row->account_number);
            } catch (DecryptException) {
                return; // already plaintext
            }

            DB::table('payout_accounts')->where('id', $row->id)->update(['account_number' => $plain]);
        });

        Schema::table('payout_accounts', function (Blueprint $table) {
            $table->string('account_number', 20)->change();
        });
    }
};