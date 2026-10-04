<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (PRO-05): marks on the pair "psychologist — client" and private notes of the psychologist.
 * The list of clients itself is computed from therapy_sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            // Mark «работа завершена» set by the psychologist.
            $table->timestamp('work_finished_at')->nullable();
            // The client changed psychologist (book.psychologist.changed).
            $table->timestamp('changed_psychologist_at')->nullable();
            // DM-08: after the change or the finish mark the psychologist sees diary data only before this moment.
            // A new booking made after it lifts the restriction (the work resumed).
            $table->timestamp('access_until')->nullable();
            $table->timestamp('notes_purged_at')->nullable();
            $table->timestamps();
            $table->unique(['psychologist_id', 'client_id']);
            $table->index('client_id');
        });

        // Private notes: visible only to the author; no role or permission grants access (TZ v2, section 8).
        Schema::create('psychologist_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('therapy_sessions')->nullOnDelete();
            // Encrypted at rest (Laravel "encrypted" cast).
            $table->text('body');
            $table->timestamps();
            $table->index(['psychologist_id', 'client_id', 'created_at']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psychologist_notes');
        Schema::dropIfExists('client_cards');
    }
};
