<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PSY (ST-08, ST-09) and SCHED. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psychologists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug', 128)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 16)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->foreignUuid('photo_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            // Video card (DEC-44) is moderated separately: none | pending | approved | rejected.
            $table->foreignUuid('video_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->string('video_status', 16)->default('none');
            $table->text('video_comment')->nullable();
            $table->string('headline')->nullable();
            $table->text('about')->nullable();
            $table->unsignedSmallInteger('experience_years')->nullable();
            $table->jsonb('education')->nullable();
            $table->boolean('works_individual')->default(true);
            $table->boolean('works_pair')->default(false);
            $table->unsignedBigInteger('price_individual')->nullable();
            $table->unsignedBigInteger('price_pair')->nullable();
            $table->foreignUuid('price_category_id')->nullable()->constrained()->nullOnDelete();
            // ST-08: draft | in_review | approved | rejected
            $table->string('qualification_status', 16)->default('draft')->index();
            $table->text('qualification_comment')->nullable();
            $table->timestamp('qualification_submitted_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            // ST-09: grace | active_not_met | active_met | inactive (null until qualified)
            $table->string('activity_status', 16)->nullable()->index();
            // BR-PSY-06: active | paused | blocked
            $table->string('work_status', 16)->default('active')->index();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            // Pending edits of public fields of an approved profile (site shows the old version until moderated).
            $table->jsonb('pending_changes')->nullable();
            $table->timestamp('pending_submitted_at')->nullable();
            $table->string('timezone', 64)->default('Europe/Moscow');
            // Psychologist may tighten platform limits (P-BOOK-MIN-LEAD, P-BOOK-HORIZON, P-BUFFER).
            $table->unsignedInteger('min_lead_minutes')->nullable();
            $table->unsignedSmallInteger('horizon_days')->nullable();
            $table->unsignedSmallInteger('buffer_minutes')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approach_psychologist', function (Blueprint $table) {
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('approach_id')->constrained()->cascadeOnDelete();
            // "Подход с пояснением": how this psychologist applies the approach.
            $table->text('explanation')->nullable();
            $table->primary(['psychologist_id', 'approach_id']);
        });

        Schema::create('psychologist_specialization', function (Blueprint $table) {
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('specialization_id')->constrained()->cascadeOnDelete();
            $table->primary(['psychologist_id', 'specialization_id']);
        });

        Schema::create('client_request_psychologist', function (Blueprint $table) {
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_request_id')->constrained()->cascadeOnDelete();
            $table->primary(['psychologist_id', 'client_request_id']);
        });

        Schema::create('qualification_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('stored_files')->restrictOnDelete();
            // diploma | retraining | certificate | other
            $table->string('kind', 32);
            $table->string('title');
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // pending | approved | rejected
            $table->string('status', 16)->default('pending')->index();
            $table->text('comment')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('psychologist_price_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price_individual')->nullable();
            $table->unsignedBigInteger('price_pair')->nullable();
            $table->foreignUuid('price_category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });

        // SCHED: weekly working intervals in the psychologist's timezone; gaps between intervals are breaks.
        Schema::create('schedule_intervals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // ISO 1 = Monday … 7 = Sunday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
            $table->index(['psychologist_id', 'weekday']);
        });

        // Vacations and blocked dates.
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            // vacation | blocked
            $table->string('kind', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('comment')->nullable();
            $table->timestamps();
            $table->index(['psychologist_id', 'starts_at']);
        });

        // P-SLOT-HOLD: a slot is held while the client completes the booking.
        Schema::create('slot_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('guest_token', 64)->nullable()->index();
            $table->string('format', 16)->default('individual');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'slot_holds', 'schedule_exceptions', 'schedule_intervals', 'psychologist_price_history', 'qualification_documents',
            'client_request_psychologist', 'psychologist_specialization', 'approach_psychologist', 'psychologists',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
