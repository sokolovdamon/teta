<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY (stream B1): card bindings, encrypted card tokens, charge task links, certificate funds in balance spends,
 * complaint dialogue and SLA marks, gift certificate codes stored as hashes (ST-18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable();
            $table->string('card_mask', 32)->nullable();
            $table->text('return_url')->nullable();
            $table->string('error_message')->nullable();
        });

        // Room for longer provider tokens (and for encrypting them at rest, SEQ-01).
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->text('token')->change();
        });

        // Card binding with the payer (3-D Secure) — WIZ-06, CL-07, PRO-09.
        Schema::create('card_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('idempotency_key', 128)->unique();
            $table->string('gateway_id')->nullable()->index();
            // pending | succeeded | declined
            $table->string('status', 16)->default('pending')->index();
            // payment | payout
            $table->string('purpose', 16)->default('payment');
            $table->text('confirmation_url')->nullable();
            $table->string('return_path')->nullable();
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->string('error_category', 16)->nullable();
            $table->timestamps();
        });

        Schema::table('charge_tasks', function (Blueprint $table) {
            $table->uuid('balance_operation_id')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            // Payment with the payer started from CL-07 ("оплатить другой картой").
            $table->uuid('payer_payment_id')->nullable();
        });

        Schema::table('client_balance_operations', function (Blueprint $table) {
            // Part of a spend paid from certificate funds (certificate funds are spent first and never withdrawn).
            $table->unsignedBigInteger('certificate_amount')->default(0);
            $table->jsonb('meta')->nullable();
        });

        Schema::table('charge_complaints', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_charged')->default(0);
            $table->decimal('share_percent', 7, 4)->nullable();
            // [{from: client|admin, text, at}]
            $table->jsonb('messages')->nullable();
            // soon (< 3 working days left) | overdue
            $table->string('sla_level', 16)->nullable();
            $table->timestamp('sla_alerted_at')->nullable();
            $table->timestamp('taken_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
        });

        Schema::table('gift_certificates', function (Blueprint $table) {
            // Only the hash of the code is stored (ST-18); the code itself is sent by email once.
            $table->string('code', 32)->nullable()->change();
            $table->string('code_hash', 64)->nullable()->unique();
            $table->string('code_hint', 8)->nullable();
            $table->string('buyer_name')->nullable();
            // recipient | buyer
            $table->string('send_to', 16)->default('recipient');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->uuid('balance_operation_id')->nullable();
        });

        Schema::table('payment_refunds', function (Blueprint $table) {
            $table->string('error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', fn (Blueprint $t) => $t->dropColumn('error_code'));
        Schema::table('gift_certificates', function (Blueprint $table) {
            $table->dropUnique(['code_hash']);
            $table->dropColumn(['code_hash', 'code_hint', 'buyer_name', 'send_to', 'sent_at', 'expired_at', 'balance_operation_id']);
        });
        Schema::table('charge_complaints', fn (Blueprint $t) => $t->dropColumn(['amount_charged', 'share_percent', 'messages', 'sla_level', 'sla_alerted_at', 'taken_at', 'withdrawn_at']));
        Schema::table('client_balance_operations', fn (Blueprint $t) => $t->dropColumn(['certificate_amount', 'meta']));
        Schema::table('charge_tasks', fn (Blueprint $t) => $t->dropColumn(['balance_operation_id', 'last_error_code', 'last_attempt_at', 'payer_payment_id']));
        Schema::dropIfExists('card_bindings');
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['metadata', 'card_mask', 'return_url', 'error_message']));
    }
};
