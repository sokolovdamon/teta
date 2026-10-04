<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOOK (stream B1): payment details of a session, choice after a psychologist's cancel or no-show, reminders,
 * pair partner invitation (Q-54), "Нет подходящего времени" answers (DEC-28), quality incidents (BR-CANC-08)
 * and booking intents — a slot held while the payer pays a late booking in the checkout (BR-BOOK-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('therapy_sessions', function (Blueprint $table) {
            // Card payment that paid the session (moves to the new session on a free reschedule, BR-CANC-06).
            $table->foreignUuid('payment_id')->nullable()->after('payment_source')->constrained()->nullOnDelete();
            // Money taken from the client for this session: card + cabinet balance (incl. certificate funds).
            $table->unsignedBigInteger('amount_charged')->default(0)->after('amount_due');
            $table->unsignedBigInteger('paid_card')->default(0);
            $table->unsignedBigInteger('paid_balance')->default(0);
            $table->unsignedBigInteger('paid_certificate')->default(0);
            // Part of amount_charged returned to the cabinet balance (Q-52).
            $table->unsignedBigInteger('balance_refunded')->default(0);
            // free_cancel | late_cancel | change_psychologist | psy_cancel | charge_failed | block | admin | unpaid
            $table->string('cancel_kind', 32)->nullable();
            $table->timestamp('choice_deadline_at')->nullable();
            // Reminder thresholds (minutes before start) already sent: P-REMINDERS.
            $table->jsonb('reminders_sent')->nullable();
            $table->timestamp('partner_invited_at')->nullable();
            $table->timestamp('partner_accepted_at')->nullable();
            // psychologist | auto | admin
            $table->string('outcome_source', 16)->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->index(['status', 'charge_due_at']);
        });

        Schema::table('session_time_requests', function (Blueprint $table) {
            $table->string('format', 16)->default('individual');
            $table->text('psychologist_comment')->nullable();
            $table->jsonb('offered_slots')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['psychologist_id', 'status']);
        });

        // BR-CANC-08: late cancel or no-show of a psychologist; P-QUALITY-INCIDENT-THRESHOLD in 30 days → admins.
        Schema::create('quality_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            // late_cancel | no_show
            $table->string('kind', 32);
            $table->timestamp('created_at');
            $table->index(['psychologist_id', 'created_at']);
        });

        // A late booking (< P-CHARGE-OFFSET) is paid before the session exists; the slot stays held (BR-BOOK-04).
        Schema::create('booking_intents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('psychologist_id')->constrained()->cascadeOnDelete();
            $table->uuid('slot_hold_id')->nullable();
            $table->string('format', 16);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('duration_min');
            $table->unsignedBigInteger('price');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('amount_due');
            $table->unsignedBigInteger('balance_part')->default(0);
            $table->string('promo_code', 64)->nullable();
            $table->jsonb('data')->nullable();
            $table->uuid('balance_operation_id')->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            // pending | completed | failed | expired
            $table->string('status', 16)->default('pending')->index();
            $table->foreignUuid('therapy_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_intents');
        Schema::dropIfExists('quality_incidents');
        Schema::table('session_time_requests', function (Blueprint $table) {
            $table->dropIndex(['psychologist_id', 'status']);
            $table->dropColumn(['format', 'psychologist_comment', 'offered_slots', 'answered_at', 'closed_at']);
        });
        Schema::table('therapy_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'charge_due_at']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn([
                'amount_charged', 'paid_card', 'paid_balance', 'paid_certificate', 'balance_refunded', 'cancel_kind',
                'choice_deadline_at', 'reminders_sent', 'partner_invited_at', 'partner_accepted_at', 'outcome_source', 'idempotency_key',
            ]);
        });
    }
};
