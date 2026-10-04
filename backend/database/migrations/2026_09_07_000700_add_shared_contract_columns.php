<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shared columns and tables used across streams: session join marks (MEET → BOOK) and the monthly supervision requirement (SUPERV → PSY, PAYOUT). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            // Filled by TetaMeet from the session log (ROOM-05); used for automatic outcomes (ST-01).
            $table->timestamp('client_joined_at')->nullable();
            $table->timestamp('psychologist_joined_at')->nullable();
        });

        // ST-09: status of the monthly supervision requirement per psychologist and calendar month (MSK).
        Schema::create('supervision_month_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            // met | exempt
            $table->string('status', 16);
            $table->nullableUuidMorphs('source');
            $table->foreignUuid('credited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('met_at')->nullable();
            $table->timestamps();
            $table->unique(['psychologist_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervision_month_requirements');
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropColumn(['client_joined_at', 'psychologist_joined_at']);
        });
    }
};
