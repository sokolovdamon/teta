<?php

namespace App\Modules\Promo\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\ReferralInvite;
use App\Modules\Promo\Models\ReferralLink;
use App\Support\Settings\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Пригласи друга" (CL-13, DEC-42, SEQ-19, BR-PROMO-11) on individual promo codes:
 * the friend registers by the personal link → an individual first-session code for the friend;
 * the friend's first paid session (outside a corporate program) → an individual reward code for the inviter.
 * Self-invites (same email or same card), friends who already were clients and duplicates get no reward.
 */
class ReferralService
{
    public function __construct(private PromoCodeGenerator $generator, private Notifier $notifier, private PromoService $promo) {}

    public function linkFor(User $user): ReferralLink
    {
        $existing = ReferralLink::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }
        try {
            return ReferralLink::create(['user_id' => $user->id, 'code' => $this->generator->one(null, 8)]);
        } catch (UniqueConstraintViolationException) {
            return ReferralLink::where('user_id', $user->id)->firstOrFail();
        }
    }

    public function url(ReferralLink $link): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/auth/register?ref='.$link->code;
    }

    /** auth.user.registered with referral_code: invite record and the friend's first-session code. */
    public function onRegistered(User $invitee, string $code): ?ReferralInvite
    {
        $link = ReferralLink::with('user')->where('code', PromoCode::normalize($code))->first();
        if (! $link || ! $link->user || ReferralInvite::where('invitee_id', $invitee->id)->exists()) {
            return null;
        }
        $inviter = $link->user;

        $result = DB::transaction(function () use ($inviter, $invitee) {
            $invite = ReferralInvite::create([
                'inviter_id' => $inviter->id,
                'invitee_id' => $invitee->id,
                'invitee_email' => $invitee->email,
                'status' => 'registered',
                'registered_at' => now(),
            ]);

            $rejected = match (true) {
                $inviter->id === $invitee->id || self::sameEmail($inviter->email, $invitee->email) => 'self_invite',
                ! $invitee->hasRole('client') => 'not_client',
                $this->promo->hasPaidSessions($invitee) => 'not_new_client',
                default => null,
            };
            if ($rejected) {
                $invite->forceFill(['status' => 'rejected', 'rejected_reason' => $rejected])->save();

                return [$invite, null];
            }

            $promo = $this->issueCode($invitee, 'first_session', Settings::int('P-REFERRAL-FRIEND-DISCOUNT'), 'Пригласи друга: первая сессия', $invite);
            $invite->forceFill(['promo_code_id' => $promo->id])->save();

            return [$invite, $promo];
        });

        [$invite, $promo] = $result;
        if ($promo) {
            $this->notifier->send($invitee, 'promo.referral_friend_code', $this->vars($promo, $invitee), '/psychologists');
        }

        return $invite;
    }

    /** book.session.paid: the friend's first paid session outside a corporate program rewards the inviter once. */
    public function onSessionPaid(TherapySession $session): ?ReferralInvite
    {
        if ($session->isCorporate()) {
            return null;
        }
        $invite = ReferralInvite::where('invitee_id', $session->client_id)->where('status', 'registered')->first();
        if (! $invite) {
            return null;
        }

        $result = DB::transaction(function () use ($invite, $session) {
            $invite = ReferralInvite::whereKey($invite->id)->lockForUpdate()->first();
            if (! $invite || $invite->status !== 'registered') {
                return null;
            }
            $inviter = User::find($invite->inviter_id);
            if (! $inviter || in_array($inviter->status, [User::STATUS_DELETED], true)) {
                return null;
            }
            if ($this->sameCard($invite->inviter_id, $invite->invitee_id)) {
                $invite->forceFill(['status' => 'rejected', 'rejected_reason' => 'same_card'])->save();

                return null;
            }

            $type = Settings::get('P-REFERRAL-REWARD-TYPE') === 'percent' ? 'percent' : 'fixed';
            $promo = $this->issueCode($inviter, $type, Settings::int('P-REFERRAL-REWARD-VALUE'), 'Пригласи друга: награда за друга', $invite);
            $invite->forceFill([
                'status' => 'rewarded',
                'reward_code_id' => $promo->id,
                'rewarded_at' => now(),
                'rewarded_session_id' => $session->id,
            ])->save();

            return [$invite, $promo, $inviter];
        });
        if (! $result) {
            return null;
        }
        [$invite, $promo, $inviter] = $result;
        $this->notifier->send($inviter, 'promo.referral_reward', $this->vars($promo, $inviter), '/client/invite');

        return $invite;
    }

    /** CL-13 data: personal link, terms, invited friends and rewards. */
    public function overview(User $user): array
    {
        $link = $this->linkFor($user);
        $invites = ReferralInvite::with(['invitee', 'rewardCode'])->where('inviter_id', $user->id)->latest()->get();
        $ownCode = PromoCode::where('owner_user_id', $user->id)->where('source', 'referral')->where('type', 'first_session')->latest()->first();
        $rewardType = Settings::get('P-REFERRAL-REWARD-TYPE') === 'percent' ? 'percent' : 'fixed';

        return [
            'code' => $link->code,
            'url' => $this->url($link),
            'terms' => [
                'friend_discount_percent' => Settings::int('P-REFERRAL-FRIEND-DISCOUNT'),
                'reward_type' => $rewardType,
                'reward_value' => Settings::int('P-REFERRAL-REWARD-VALUE'),
                'validity_days' => Settings::int('P-REFERRAL-CODE-VALIDITY'),
            ],
            'stats' => [
                'invited' => $invites->count(),
                'registered' => $invites->whereIn('status', ['registered', 'rewarded'])->count(),
                'rewarded' => $invites->where('status', 'rewarded')->count(),
            ],
            'invites' => $invites->map(fn (ReferralInvite $i) => [
                'id' => $i->id,
                'friend' => $i->invitee ? self::maskName($i->invitee) : null,
                'status' => $i->status,
                'status_label' => ReferralInvite::STATUS_LABELS[$i->status] ?? $i->status,
                'rejected_reason' => $i->rejected_reason ? (ReferralInvite::REJECTED_LABELS[$i->rejected_reason] ?? $i->rejected_reason) : null,
                'registered_at' => $i->registered_at?->toIso8601String(),
                'rewarded_at' => $i->rewarded_at?->toIso8601String(),
            ])->values()->all(),
            'rewards' => $invites->filter(fn (ReferralInvite $i) => $i->rewardCode)->map(fn (ReferralInvite $i) => self::codeRow($i->rewardCode))->values()->all(),
            'friend_code' => $ownCode ? self::codeRow($ownCode) : null,
        ];
    }

    public static function codeRow(PromoCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'type' => $code->type,
            'value' => (int) $code->value,
            'discount_label' => $code->discountLabel(),
            'status' => $code->status,
            'status_label' => PromoCode::STATUS_LABELS[$code->status] ?? $code->status,
            'valid_until' => $code->valid_until?->toIso8601String(),
            'used' => (int) $code->uses_count > 0,
        ];
    }

    /** Same mailbox: case, "+tag" and dots in Gmail addresses are ignored. */
    public static function sameEmail(string $a, string $b): bool
    {
        return self::normalizeEmail($a) === self::normalizeEmail($b);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (! str_contains($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local, 2)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return $local.'@'.$domain;
    }

    private function sameCard(string $inviterId, string $inviteeId): bool
    {
        $inviter = PaymentMethod::where('user_id', $inviterId)->get(['token', 'card_mask', 'exp_month', 'exp_year']);
        if ($inviter->isEmpty()) {
            return false;
        }

        return PaymentMethod::where('user_id', $inviteeId)->get(['token', 'card_mask', 'exp_month', 'exp_year'])
            ->contains(fn (PaymentMethod $card) => $inviter->contains(fn (PaymentMethod $own) => $own->token === $card->token
                || ($card->card_mask && $own->card_mask === $card->card_mask && $own->exp_month === $card->exp_month && $own->exp_year === $card->exp_year)));
    }

    private function issueCode(User $owner, string $type, int $value, string $title, ReferralInvite $invite): PromoCode
    {
        $promo = new PromoCode([
            'code' => $this->generator->one('DRUG', 6),
            'title' => $title,
            'type' => $type,
            'value' => $value,
            'kind' => 'individual',
            'source' => 'referral',
            'owner_user_id' => $owner->id,
            'valid_from' => now(),
            'valid_until' => now()->addDays(Settings::int('P-REFERRAL-CODE-VALIDITY'))->endOfDay(),
            'total_limit' => 1,
            'per_user_limit' => 1,
            'published_at' => now(),
        ]);
        $promo->forceFill(['status' => 'active'])->save();
        $promo->recordInitialState(null, ['referral_invite_id' => $invite->id]);

        return $promo;
    }

    private function vars(PromoCode $promo, User $user): array
    {
        return [
            'code' => $promo->code,
            'discount' => $promo->discountLabel(),
            'valid_until' => $promo->valid_until?->setTimezone($user->timezone ?: config('platform.timezone'))->locale('ru')->translatedFormat('j F Y'),
        ];
    }

    private static function maskName(User $user): string
    {
        return trim($user->name.' '.($user->last_name ? mb_substr($user->last_name, 0, 1).'.' : ''));
    }
}
