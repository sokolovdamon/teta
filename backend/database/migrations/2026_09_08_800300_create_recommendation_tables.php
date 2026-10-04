<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RECO (PRO-07, CL-05, DM-12): recommendations after a held session.
 * Statuses: draft → sent → viewed → done; sent → revoked (only before viewing).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('therapy_sessions')->cascadeOnDelete();
            // task | exercise | material
            $table->string('type', 16);
            $table->string('title', 200);
            $table->text('body')->nullable();
            // [{url, title?, kb_material_id?}] — external links and knowledge base materials.
            $table->jsonb('links')->nullable();
            $table->date('due_date')->nullable();
            // draft | sent | viewed | done | revoked
            $table->string('status', 16)->default('draft')->index();
            // Session end + P-RECO-WINDOW in force when the draft was created (sequences_states.md, rule 5).
            $table->timestamp('window_until');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status']);
            $table->index(['psychologist_id', 'client_id']);
        });

        Schema::create('recommendation_files', function (Blueprint $table) {
            $table->foreignUuid('recommendation_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stored_file_id')->constrained('stored_files')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->primary(['recommendation_id', 'stored_file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_files');
        Schema::dropIfExists('recommendations');
    }
};
