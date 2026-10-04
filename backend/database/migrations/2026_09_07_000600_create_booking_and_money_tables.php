<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOOK (ST-01), PAY (ST-02…ST-05), PAYOUT (ST-06, ST-07), gift certificates (ST-18).
 * Money is stored in kopecks. Card data is never stored: only gateway tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('therapy_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('psychologist_id')->constrained()->restrictOnDelete();
            // individual (50 min) | pair (90 min)
            $table->string('format', 16)->default('individual');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('duration_min');
            // ST-01: booked | paid | in_progress | held | client_no_show | psy_no_show | tech_issue
            //        | cancelled_by_client | cancelled_by_psy | cancelled_by_system
            $table->string('status', 32)->default('booked')->index();
            // Price fixed at booking (BR-BOOK-03); discount reduces only the platform share (DEC-57).
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('amount_due');
            $table->foreignUuid('promo_code_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('corporate_participation_id')->nullable()->constrained()->nullOnDelete();
            // card | balance | mixed | corporate | free
            $table->string('payment_source', 16)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('charge_due_at')->nullable();
            $table->timestamp('charge_deadline_at')->nullable();
            $table->string('client_timezone', 64)->default('Europe/Moscow');
            // After psychologist cancel / no-show / tech issue: pending | refund | reschedule
            $table->string('client_choice', 16)->nullable();
            $table->uuid('rescheduled_from_id')->nullable()->index();
            $table->unsignedSmallInteger('reschedule_count')->default(0);
            $table->unsignedSmallInteger('late_reschedule_count')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->foreignUuid('outcome_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('actual_duration_sec')->nullable();
            $table->unsignedInteger('joint_duration_sec')->nullable();
            // Pair session: the second participant joins by email invitation with own account (Q-54).
            $table->string('partner_email')->nullable();
            $table->foreignUuid('partner_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Requests the client chose for this booking — "сведения о состоянии", visible only to the psychologist.
            $table->jsonb('client_request_ids')->nullable();
            // wizard | catalog | reschedule | corporate | admin
            $table->string('source', 16)->default('catalog');
            // Parameter values in force at booking (P-CHARGE-OFFSET etc.).
            $table->jsonb('params')->nullable();
            $table->timestamps();
            $table->index(['psychologist_id', 'starts_at']);
            $table->index(['client_id', 'starts_at']);
        });

        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->foreign('rescheduled_from_id')->references('id')->on('therapy_sessions')->nullOnDelete();
        });

        // "Нет подходящего времени" (DEC-28).
        Schema::create('session_time_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->jsonb('preferred')->nullable();
            $table->text('comment')->nullable();
            // open | offered | booked | closed
            $table->string('status', 16)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('token');
            // payment (client card, psychologist card for supervision) | payout (self-employed card)
            $table->string('purpose', 16)->default('payment');
            $table->string('card_mask', 32)->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->boolean('is_default')->default(true);
            // active | removed
            $table->string('status', 16)->default('active')->index();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // session | certificate | supervision | event | b2b_invoice | card_binding
            $table->string('purpose', 32)->index();
            $table->nullableUuidMorphs('payable');
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('currency', 3)->default('RUB');
            // ST-03: created | requires_3ds | unknown | succeeded | declined | partially_refunded | refunded
            $table->string('status', 32)->default('created')->index();
            $table->string('gateway', 32);
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('idempotency_key', 128)->unique();
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('with_payer')->default(false);
            $table->text('confirmation_url')->nullable();
            $table->string('error_code', 64)->nullable();
            // retry | no_retry | new_card
            $table->string('error_category', 16)->nullable();
            $table->string('description')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('charge_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('therapy_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_part')->default(0);
            $table->timestamp('due_at')->index();
            $table->timestamp('deadline_at');
            // ST-02: scheduled | in_progress | retry_wait | succeeded | failed_final | cancelled
            $table->string('status', 16)->default('scheduled')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->string('last_error_category', 16)->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('charge_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('charge_task_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('result', 16);
            $table->string('error_category', 16)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            // pending | succeeded | failed | review
            $table->string('status', 16)->default('pending')->index();
            $table->string('idempotency_key', 128)->unique();
            $table->string('gateway_refund_id')->nullable();
            $table->nullableUuidMorphs('source');
            $table->text('reason')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 54-ФЗ receipts: PREPAYMENT_FULL for B2C autocharge, CREDIT_PAYMENT for B2B invoices (DEC-22).
        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_refund_id')->nullable()->constrained()->nullOnDelete();
            // income | income_return
            $table->string('kind', 16);
            $table->string('calculation_method', 32);
            $table->unsignedBigInteger('amount');
            $table->jsonb('items');
            $table->string('customer_email')->nullable();
            // pending | registered | failed
            $table->string('status', 16)->default('pending');
            $table->jsonb('fiscal_data')->nullable();
            $table->timestamps();
        });

        // ST-04: every operation on the client cabinet balance. Balance = credits − spends − withdrawals in force.
        Schema::create('client_balance_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // credit | spend | withdraw
            $table->string('type', 16);
            // credited | spend_reserved | spent | spend_reversed | withdraw_reserved | withdraw_processing
            // | withdrawn | withdraw_review | withdraw_cancelled
            $table->string('status', 32)->index();
            $table->unsignedBigInteger('amount');
            // psy_cancel | psy_no_show | tech_issue | change_psychologist | complaint | block | certificate | admin | session_payment | withdrawal
            $table->string('reason', 32);
            $table->nullableUuidMorphs('source');
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            // Certificate funds are spendable but never withdrawn to a card.
            $table->boolean('is_certificate_funds')->default(false);
            $table->text('comment')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'type', 'status']);
        });

        Schema::create('client_balances', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('available')->default(0);
            $table->bigInteger('reserved')->default(0);
            $table->bigInteger('certificate_available')->default(0);
            $table->timestamps();
        });

        // ST-05: complaint about a charge, decided within 14 working days (DEC-23).
        Schema::create('charge_complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('therapy_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            // submitted | in_review | waiting_client | rejected | approved | refunded | withdrawn
            $table->string('status', 16)->default('submitted')->index();
            $table->date('due_date');
            $table->foreignUuid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->unsignedBigInteger('refund_amount')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 32);
            $table->string('external_id', 128);
            $table->string('event_type', 64);
            $table->jsonb('payload');
            $table->boolean('signature_valid')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });

        Schema::create('gift_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->unsignedBigInteger('nominal');
            $table->foreignUuid('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_email');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->text('message')->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            // ST-18: awaiting_payment | unpaid | paid | activated | expired
            $table->string('status', 16)->default('awaiting_payment')->index();
            $table->timestamp('valid_until')->nullable();
            $table->foreignUuid('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('promo_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('discount_amount');
            // reserved | applied | restored
            $table->string('status', 16)->default('reserved')->index();
            $table->timestamps();
        });

        // ST-06: accrual to a psychologist or supervisor; balance is shown net of commission (DEC-20).
        Schema::create('accruals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->uuidMorphs('source');
            // session | client_no_show | late_cancel | supervision | correction
            $table->string('kind', 32);
            $table->unsignedBigInteger('base_amount');
            $table->unsignedTinyInteger('commission_percent');
            $table->bigInteger('amount');
            $table->unsignedBigInteger('reversed_amount')->default(0);
            // accrued | in_registry | paid | reversed | corrected
            $table->string('status', 16)->default('accrued')->index();
            $table->uuid('payout_id')->nullable()->index();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('payee_balances', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->bigInteger('available')->default(0);
            $table->bigInteger('in_payout')->default(0);
            $table->bigInteger('paid_total')->default(0);
            $table->boolean('payouts_suspended')->default(false);
            $table->text('suspended_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('payout_registries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            // draft | approved | sent | completed
            $table->string('status', 16)->default('draft')->index();
            $table->boolean('auto_approve')->default(false);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // ST-07: weekly payout to one psychologist or supervisor.
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payout_registry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            // checking | blocked_supervision | deferred | in_registry | excluded | sent | unknown | paid | rejected
            $table->string('status', 32)->default('checking')->index();
            $table->text('reason')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->string('gateway_payout_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'payouts', 'payout_registries', 'payee_balances', 'accruals', 'promo_redemptions', 'gift_certificates',
            'webhook_inbox', 'charge_complaints', 'client_balances', 'client_balance_operations', 'receipts',
            'payment_refunds', 'charge_attempts', 'charge_tasks', 'payments', 'payment_methods',
            'session_time_requests', 'therapy_sessions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
