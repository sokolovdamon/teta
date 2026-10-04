<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Card tokens are stored encrypted (SEQ-01); a SHA-256 hash is kept for lookups by token. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->string('token_hash', 64)->nullable()->index();
        });

        DB::table('payment_methods')->orderBy('id')->each(function ($row) {
            DB::table('payment_methods')->where('id', $row->id)->update([
                'token_hash' => hash('sha256', $row->token),
                'token' => Crypt::encryptString($row->token),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('payment_methods')->orderBy('id')->each(function ($row) {
            DB::table('payment_methods')->where('id', $row->id)->update(['token' => Crypt::decryptString($row->token)]);
        });
        Schema::table('payment_methods', fn (Blueprint $table) => $table->dropColumn('token_hash'));
    }
};
