<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DIARY (DEC-41, CL-01, CL-06, PRO-06): emotion diary of a client.
 * Entries are "сведения о состоянии": never copied to letters, logs, analytics or audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fixed list of emotion tags, edited by admins (ADM-13).
        Schema::create('emotion_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('title', 64);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('diary_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            // 5-emoji scale: 1 — very bad … 5 — very good.
            $table->unsignedTinyInteger('mood');
            // Emotion tag ids (emotion_tags.id); unknown ids are ignored on display.
            $table->jsonb('tag_ids')->nullable();
            // Note for the client only, encrypted at rest; never shown to the psychologist (BR-DIARY-03).
            $table->text('note')->nullable();
            $table->timestamp('recorded_at');
            // Calendar date in the client's time zone at the moment of the entry: daily prompt and dynamics.
            $table->date('local_date');
            $table->timestamps();
            $table->index(['client_id', 'recorded_at']);
            $table->index(['client_id', 'local_date']);
        });

        // "Offered at login not more than once a day and can be skipped".
        Schema::create('diary_prompt_states', function (Blueprint $table) {
            $table->foreignUuid('client_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->date('dismissed_on')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_prompt_states');
        Schema::dropIfExists('diary_entries');
        Schema::dropIfExists('emotion_tags');
    }
};
