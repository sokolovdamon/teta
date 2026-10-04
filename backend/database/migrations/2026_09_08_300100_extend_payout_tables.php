<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYOUT (ST-06, ST-07, SEQ-08): ledger details for accruals, payout suspension, registry periods,
 * and the self-employed payout card binding (PRO-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accruals', function (Blueprint $table) {
            // When the money was earned (session outcome, supervision); the weekly registry takes accruals up to Sunday.
            $table->timestamp('occurred_at')->nullable()->index();
            // Negative correction carried into the next payouts (BR-PAYOUT-12) points to the accrual it corrects.
            $table->uuid('correction_of_id')->nullable()->index();
            // Reversals and corrections with reasons and idempotency keys: [{key, type, amount, reason, at}].
            $table->jsonb('adjustments')->nullable();
            // Display data fixed at accrual time: format, discount, payment source, corporate flag.
            $table->jsonb('meta')->nullable();
            $table->index(['user_id', 'status']);
        });
        Schema::table('accruals', function (Blueprint $table) {
            $table->foreign('correction_of_id')->references('id')->on('accruals')->nullOnDelete();
        });

        Schema::table('payee_balances', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable();
            $table->foreignUuid('suspended_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('payout_registries', function (Blueprint $table) {
            $table->unique(['period_start', 'period_end']);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('error_code', 64)->nullable();
            $table->timestamp('status_checked_at')->nullable();
            $table->index(['user_id', 'status']);
        });

        // PRO-09: binding of the self-employed card for payouts (payment method purpose "payout").
        Schema::create('payout_card_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128)->unique();
            // pending | succeeded | declined
            $table->string('status', 16)->default('pending')->index();
            $table->text('confirmation_url')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_card_bindings');
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['error_code', 'status_checked_at']);
        });
        Schema::table('payout_registries', function (Blueprint $table) {
            $table->dropUnique(['period_start', 'period_end']);
            $table->dropColumn(['sent_at', 'completed_at']);
        });
        Schema::table('payee_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn('suspended_at');
        });
        Schema::table('accruals', function (Blueprint $table) {
            $table->dropForeign(['correction_of_id']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['occurred_at', 'correction_of_id', 'adjustments', 'meta']);
        });
    }
};
