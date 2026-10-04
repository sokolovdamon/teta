<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PROMO (Э8, ST-17) and the referral program (CL-13, DEC-42, SEQ-19). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            // Published terms of the promotion (BR-PROMO-13).
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->index(['kind', 'source']);
            $table->index('owner_user_id');
        });

        Schema::table('promo_batches', function (Blueprint $table) {
            $table->string('prefix', 16)->nullable();
            $table->text('description')->nullable();
        });

        Schema::table('promo_redemptions', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->index(['promo_code_id', 'status']);
            $table->index(['user_id', 'status']);
        });
        // One promo code per session (no stacking): at most one live redemption per session.
        DB::statement("CREATE UNIQUE INDEX promo_redemptions_live_session_unique ON promo_redemptions (therapy_session_id) WHERE status IN ('reserved', 'applied')");

        // Personal invite link of a client (CL-13).
        Schema::create('referral_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 32)->unique();
            $table->timestamps();
        });

        Schema::table('referral_invites', function (Blueprint $table) {
            $table->unique('invitee_id');
            $table->index('inviter_id');
            // self_invite | not_new_client | not_client | same_card
            $table->string('rejected_reason', 32)->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->foreignUuid('rewarded_session_id')->nullable()->constrained('therapy_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referral_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rewarded_session_id');
            $table->dropUnique(['invitee_id']);
            $table->dropIndex(['inviter_id']);
            $table->dropColumn(['rejected_reason', 'registered_at', 'rewarded_at']);
        });
        Schema::dropIfExists('referral_links');
        DB::statement('DROP INDEX IF EXISTS promo_redemptions_live_session_unique');
        Schema::table('promo_redemptions', function (Blueprint $table) {
            $table->dropIndex(['promo_code_id', 'status']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['applied_at', 'restored_at']);
        });
        Schema::table('promo_batches', function (Blueprint $table) {
            $table->dropColumn(['prefix', 'description']);
        });
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropIndex(['kind', 'source']);
            $table->dropIndex(['owner_user_id']);
            $table->dropColumn(['description', 'published_at', 'deactivated_at', 'deactivation_reason']);
        });
    }
};
