<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** B2B (11.1, DEC-25, DEC-53, ST-19) and PROMO (Э8, ST-17), referral (DEC-42). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('inn', 12)->nullable();
            $table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();
            $table->text('legal_address')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            // active | suspended | archived
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['company_id', 'user_id']);
        });

        Schema::create('corporate_programs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Employees join by this code and their work email (DEC-25).
            $table->string('code', 32)->unique();
            $table->jsonb('email_domains')->nullable();
            $table->unsignedSmallInteger('sessions_limit');
            // month | quarter | year | program
            $table->string('limit_period', 16)->default('month');
            // Price the company pays per session; psychologist accrual is always from their own price (BR-B2B-11).
            $table->unsignedBigInteger('company_session_price')->nullable();
            $table->jsonb('allowed_formats')->nullable();
            $table->foreignUuid('terms_version_id')->nullable()->constrained('legal_document_versions')->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // draft | active | suspended | finished
            $table->string('status', 16)->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('corporate_participations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('corporate_program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('work_email');
            // ST-19: invited | email_pending | terms_pending | active | limit_exhausted | disconnected | program_ended
            $table->string('status', 16)->default('invited')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->foreignUuid('consent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
            $table->unique(['corporate_program_id', 'work_email']);
        });

        Schema::create('corporate_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('corporate_program_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 32)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('sessions_count')->default(0);
            $table->unsignedBigInteger('amount');
            // draft | issued | paid | cancelled
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('act_number', 32)->nullable();
            $table->timestamp('act_issued_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->unsignedInteger('size');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('title')->nullable();
            // percent | fixed | first_session
            $table->string('type', 16);
            // Percent (1–100) for percent/first_session, kopecks for fixed.
            $table->unsignedBigInteger('value');
            // mass (каузальный) | individual
            $table->string('kind', 16)->default('mass');
            // admin | batch | referral | compensation
            $table->string('source', 16)->default('admin');
            $table->foreignUuid('promo_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->unsignedInteger('total_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable()->default(1);
            $table->unsignedBigInteger('min_amount')->nullable();
            // {service_types: [...], psychologist_ids: [...], segment: {...}}
            $table->jsonb('restrictions')->nullable();
            // ST-17: draft | scheduled | active | exhausted | expired | deactivated
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('uses_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('referral_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('invitee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitee_email')->nullable();
            $table->foreignUuid('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('reward_code_id')->nullable()->constrained('promo_codes')->nullOnDelete();
            // sent | registered | first_paid | rewarded
            $table->string('status', 16)->default('sent')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'referral_invites', 'promo_codes', 'promo_batches', 'corporate_invoices', 'corporate_participations',
            'corporate_programs', 'company_user', 'companies',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
