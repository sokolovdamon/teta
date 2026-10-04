<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage of the payment service emulator (DEC-38). It plays the role of the provider's side: operations by
 * idempotency key (a repeated key returns the same operation), card tokens, fiscal data of receipts.
 * Full card numbers are never stored — only the mask and the test behaviour derived from the number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emulator_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->uuid('user_id')->nullable();
            $table->string('card_mask', 32);
            $table->string('card_brand', 32);
            $table->unsignedTinyInteger('exp_month');
            $table->unsignedSmallInteger('exp_year');
            // success | declined | insufficient | expired | 3ds | refund_fail
            $table->string('behavior', 16);
            $table->timestamps();
        });

        Schema::create('emulator_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 128)->unique();
            // binding | charge | payment | refund | payout
            $table->string('kind', 16);
            // requires_action | awaiting_3ds | succeeded | declined | pending
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->string('description')->nullable();
            $table->text('return_url')->nullable();
            $table->uuid('user_id')->nullable();
            // Token used for charge/payout, or the token created by a binding / payment with save_card.
            $table->string('token', 64)->nullable();
            $table->boolean('save_card')->default(false);
            $table->string('card_mask', 32)->nullable();
            $table->string('card_brand', 32)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('behavior', 16)->nullable();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->string('error_category', 16)->nullable();
            $table->jsonb('receipt')->nullable();
            $table->jsonb('fiscal')->nullable();
            // Webhook intentionally not delivered (amount ending in 13 kopecks emulates a timeout / lost webhook).
            $table->boolean('webhook_suppressed')->default(false);
            $table->timestamp('webhook_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emulator_operations');
        Schema::dropIfExists('emulator_cards');
    }
};
