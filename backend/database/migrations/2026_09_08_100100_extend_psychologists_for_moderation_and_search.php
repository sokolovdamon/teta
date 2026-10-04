<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stream A: PSY moderation details and CATALOG/SEARCH.
 *  - pending changes review result (BR-PSY-05), video card shown on the site (DEC-44), work status reason (BR-PSY-06);
 *  - search_vector: maintained tsvector (Russian config) over name, headline, about, approaches, requests and
 *    specializations of the published profile, with a GIN index (SEARCH, SITE-02).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('psychologists', function (Blueprint $table) {
            // Last approved video card; the site shows it while a newer upload waits for moderation.
            $table->foreignUuid('video_approved_file_id')->nullable()->after('video_file_id')->constrained('stored_files')->nullOnDelete();
            $table->unsignedSmallInteger('video_duration_sec')->nullable()->after('video_status');
            $table->timestamp('video_submitted_at')->nullable()->after('video_comment');
            $table->timestamp('video_reviewed_at')->nullable()->after('video_submitted_at');
            // Result of the last moderation of pending changes (comment is mandatory on rejection).
            $table->text('pending_review_comment')->nullable()->after('pending_submitted_at');
            $table->timestamp('pending_reviewed_at')->nullable()->after('pending_review_comment');
            // Why the profile is paused or blocked (BR-PSY-06).
            $table->text('work_status_reason')->nullable()->after('work_status');
            $table->index(['is_published', 'qualification_status', 'work_status'], 'psychologists_catalog_idx');
        });

        DB::statement('ALTER TABLE psychologists ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX psychologists_search_vector_idx ON psychologists USING GIN (search_vector)');

        Schema::table('qualification_documents', function (Blueprint $table) {
            $table->string('specialty')->nullable()->after('institution');
            $table->index(['psychologist_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('qualification_documents', function (Blueprint $table) {
            $table->dropIndex(['psychologist_id', 'status']);
            $table->dropColumn('specialty');
        });
        DB::statement('DROP INDEX IF EXISTS psychologists_search_vector_idx');
        Schema::table('psychologists', function (Blueprint $table) {
            $table->dropIndex('psychologists_catalog_idx');
            $table->dropConstrainedForeignId('video_approved_file_id');
            $table->dropColumn([
                'video_duration_sec', 'video_submitted_at', 'video_reviewed_at', 'pending_review_comment',
                'pending_reviewed_at', 'work_status_reason', 'search_vector',
            ]);
        });
    }
};
