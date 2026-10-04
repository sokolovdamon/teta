<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform core: RBAC, documents and consents, audit, files, rule parameters,
 * production calendar, domain events outbox, state history, instance settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // RBAC (X-02): roles, permissions "section.action", matrix role → permission.
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 128)->unique();
            $table->string('section', 16)->index();
            $table->string('action', 32);
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['role_id', 'user_id']);
        });

        // CONSENT (X-03): documents with versions; consents fix the exact version accepted.
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 128)->unique();
            $table->string('title');
            // offer | privacy_policy | personal_data | terms | review_publication | mailing | cookies | corporate_terms | supervision_offer | other
            $table->string('kind', 64)->index();
            $table->boolean('requires_consent')->default(false);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        Schema::create('legal_document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('legal_document_id')->constrained()->cascadeOnDelete();
            $table->string('version', 32);
            $table->longText('body');
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['legal_document_id', 'version']);
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('legal_document_version_id')->constrained()->restrictOnDelete();
            // personal_data | offer | mailing | cookies | review_publication | corporate_terms | ...
            $table->string('purpose', 64)->index();
            // What the consent was given for, e.g. a review or a corporate participation.
            $table->nullableUuidMorphs('subject');
            $table->string('email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'purpose']);
        });

        // AUDIT (X-11): user actions with filters by user, section and period; kept 3 years.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 32)->index();
            $table->string('action', 64)->index();
            $table->nullableUuidMorphs('subject');
            $table->jsonb('changes')->nullable();
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });

        // FILES (X-09): stored on project servers in RF; private files via signed links.
        Schema::create('stored_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size');
            // public | private
            $table->string('visibility', 16)->default('private');
            // avatar | qualification | video_card | attachment | kb_media | article_image | support | recommendation | ...
            $table->string('purpose', 64)->index();
            $table->string('checksum', 64)->nullable();
            // pending | clean | infected | skipped
            $table->string('scan_status', 16)->default('skipped');
            $table->timestamps();
            $table->softDeletes();
        });

        // Rule parameters P-* (ADM-26): defaults live in config/platform.php, overrides here.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Production calendar of the RF: overrides of the regular Mon–Fri week.
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->date('date')->primary();
            $table->boolean('is_working');
            $table->string('title')->nullable();
        });

        // Transactional outbox: written in the same transaction as the change, delivered via queue "outbox".
        Schema::create('domain_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 128)->index();
            $table->string('aggregate_type', 128)->nullable();
            $table->uuid('aggregate_id')->nullable();
            $table->jsonb('payload');
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        Schema::create('processed_domain_events', function (Blueprint $table) {
            $table->uuid('event_id');
            $table->string('listener', 191);
            $table->timestamp('processed_at');
            $table->primary(['event_id', 'listener']);
        });

        // Every state machine transition (ST-01…ST-20) is written here.
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('model');
            $table->string('field', 64)->default('status');
            $table->string('from', 64)->nullable();
            $table->string('to', 64);
            $table->string('event', 128)->nullable();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at')->index();
        });

        // INSTANCE (DEC-52): one instance — one database. Branding and integrations of this instance.
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value');
            $table->timestamps();
        });

        // Registry of partner instances, kept in the main instance (ADM-21, ST-20).
        Schema::create('partner_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('server')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contract_number')->nullable();
            // ST-20: registered | deploying | configuring | operational | unavailable | updating | offboarding | archived
            $table->string('status', 32)->default('registered')->index();
            $table->string('platform_version', 32)->nullable();
            // Service heartbeat from the partner instance: only version and availability, no personal data.
            $table->string('heartbeat_token', 64)->nullable()->unique();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->jsonb('branding')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'partner_instances', 'instance_settings', 'state_transitions', 'processed_domain_events', 'domain_events',
            'calendar_days', 'platform_settings', 'stored_files', 'audit_logs', 'consents', 'legal_document_versions',
            'legal_documents', 'role_user', 'permission_role', 'permissions', 'roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
