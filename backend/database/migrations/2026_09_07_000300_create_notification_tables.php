<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** NOTIF (X-05, DEC-31): templates, in-cabinet notification centre, outgoing mail log, preferences. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 128)->unique();
            $table->string('title');
            // client | psychologist | supervisor | admin | hr | any
            $table->string('audience', 32)->default('any');
            $table->string('subject');
            $table->longText('body');
            $table->string('center_text')->nullable();
            $table->boolean('send_email')->default(true);
            $table->boolean('send_center')->default(true);
            // Transactional letters are not blocked by mailing unsubscribes.
            $table->boolean('is_transactional')->default(true);
            $table->boolean('is_active')->default(true);
            $table->jsonb('variables')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('template_code', 128)->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->jsonb('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to');
            $table->string('subject');
            $table->string('template_code', 128)->nullable();
            // transactional | marketing
            $table->string('stream', 16)->default('transactional');
            // queued | sent | failed
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('session_reminders')->default(true);
            $table->boolean('marketing_emails')->default(false);
            $table->boolean('product_news')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['notification_preferences', 'mail_messages', 'user_notifications', 'notification_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
