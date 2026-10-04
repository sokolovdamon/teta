<?php

namespace App\Modules\Promo\Services;

use App\Models\User;
use App\Modules\Booking\Contracts\CorporateCoverage;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Promo\Contracts\PromoCodes;
use App\Modules\Promo\Models\PromoCode;
use App\Modules\Promo\Models\PromoRedemption;
use App\Modules\Psychologists\Models\Psychologist;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PROMO for BOOK (Э8, ST-17, BR-PROMO-01…10). One code per session, no stacking; the discount never exceeds the price
 * and reduces only the platform share (DEC-57). Codes do not apply to corporate sessions (BR-PROMO-06).
 *
 * quote()   → validates everything and returns the discount;
 * reserve() → redemption "reserved", counts against the total and per-user limits (active → exhausted at the limit);
 * consume() → "applied" when the session is charged;
 * restore() → "restored" on a free cancellation, cancellation by the psychologist or the platform (BR-PROMO-09);
 *             an exhausted code becomes active again.
 */
class PromoService implements PromoCodes
{
    public function __construct(private CorporateCoverage $corporate) {}

    public function quote(string $code, User $client, Psychologist $psychologist, string $format, int $price): array
    {
        $promo = $this->find($code);
        $this->assertNotCorporate($this->corporate->coverFor($client, $format) !== null);
        $discount = $this->validate($promo, $client, $psychologist, $format, $price);

        return ['promo_code_id' => $promo->id, 'code' => $promo->code, 'discount' => $discount];
    }

    public function reserve(string $code, User $client, TherapySession $session): void
    {
        DB::transaction(function () use ($code, $client, $session) {
            $promo = PromoCode::where('code', PromoCode::normalize($code))->lockForUpdate()->first();
            if (! $promo) {
                $this->fail('Промокод не найден. Проверьте, правильно ли он введён.');
            }
            $live = PromoRedemption::where('therapy_session_id', $session->id)->whereIn('status', PromoRedemption::LIVE)->lockForUpdate()->first();
            if ($live) {
                if ($live->promo_code_id === $promo->id) {
                    return;
                }
                $this->fail('К этой сессии уже применён другой промокод: промокоды не суммируются.');
            }
            $this->assertNotCorporate($session->isCorporate());
            $session->loadMissing('psychologist');
            $discount = $this->validate($promo, $client, $session->psychologist, $session->format, (int) $session->price, $session->id);

            PromoRedemption::create([
                'promo_code_id' => $promo->id,
                'user_id' => $client->id,
                'therapy_session_id' => $session->id,
                'discount_amount' => $discount,
                'status' => 'reserved',
            ]);
            $promo->forceFill(['uses_count' => (int) $promo->uses_count + 1])->save();
            if ($promo->status === 'active' && $promo->limitReached()) {
                $promo->transitionTo('exhausted', null, 'Достигнут общий лимит применений', context: ['uses_count' => (int) $promo->uses_count]);
            }
        });
    }

    public function consume(TherapySession $session): void
    {
        DB::transaction(function () use ($session) {
            $redemption = PromoRedemption::where('therapy_session_id', $session->id)->where('status', 'reserved')->lockForUpdate()->first();
            $redemption?->forceFill(['status' => 'applied', 'applied_at' => now()])->save();
        });
    }

    public function restore(TherapySession $session): void
    {
        DB::transaction(function () use ($session) {
            $redemption = PromoRedemption::where('therapy_session_id', $session->id)->whereIn('status', PromoRedemption::LIVE)->lockForUpdate()->first();
            if (! $redemption) {
                return;
            }
            $redemption->forceFill(['status' => 'restored', 'restored_at' => now()])->save();

            $promo = PromoCode::whereKey($redemption->promo_code_id)->lockForUpdate()->first();
            if (! $promo) {
                return;
            }
            $promo->forceFill(['uses_count' => max(0, (int) $promo->uses_count - 1)])->save();
            if ($promo->status === 'exhausted' && ! $promo->limitReached()) {
                if ($promo->valid_until && $promo->valid_until->isPast()) {
                    $promo->transitionTo('expired', null, 'Закончился период действия');
                } else {
                    $promo->transitionTo('active', null, 'Применение восстановлено при отмене', context: ['session_id' => $session->id]);
                }
            }
        });
    }

    /** promo:sync-statuses — scheduled → active at the start, active/exhausted → expired after the end of the period. */
    public function syncStatuses(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $stats = ['activated' => 0, 'expired' => 0];
        PromoCode::where('status', 'scheduled')->where('valid_from', '<=', $now)->get()->each(function (PromoCode $p) use (&$stats) {
            $p->transitionTo('active', null, 'Наступило начало периода');
            $stats['activated']++;
        });
        PromoCode::whereIn('status', ['active', 'exhausted'])->whereNotNull('valid_until')->where('valid_until', '<', $now)->get()->each(function (PromoCode $p) use (&$stats) {
            $p->transitionTo('expired', null, 'Закончился период действия');
            $stats['expired']++;
        });

        return $stats;
    }

    public function find(string $code): PromoCode
    {
        $normalized = PromoCode::normalize($code);
        $promo = $normalized === '' ? null : PromoCode::where('code', $normalized)->first();
        if (! $promo) {
            $this->fail('Промокод не найден. Проверьте, правильно ли он введён.');
        }

        return $promo;
    }

    /** Full validation matrix (BR-PROMO-01…07); returns the discount in kopecks. */
    public function validate(PromoCode $promo, User $client, ?Psychologist $psychologist, string $format, int $price, ?string $excludeSessionId = null): int
    {
        $now = now();
        if (in_array($promo->status, ['draft', 'deactivated'], true)) {
            $this->fail('Промокод не действует.');
        }
        if ($promo->status === 'expired' || ($promo->valid_until && $promo->valid_until->lt($now))) {
            $this->fail('Срок действия промокода истёк.');
        }
        if ($promo->status === 'scheduled' || ($promo->valid_from && $promo->valid_from->gt($now))) {
            $from = $promo->valid_from?->setTimezone($client->timezone ?: config('platform.timezone'))->locale('ru')->translatedFormat('j F Y, H:i');
            $this->fail($from ? "Промокод начнёт действовать {$from}." : 'Промокод ещё не действует.');
        }
        if ($promo->status === 'exhausted' || $promo->limitReached()) {
            $this->fail('Лимит применений промокода исчерпан.');
        }
        if ($promo->owner_user_id && $promo->owner_user_id !== $client->id) {
            $this->fail('Этот промокод выдан другому пользователю.');
        }
        if ($promo->per_user_limit !== null) {
            $used = PromoRedemption::where('promo_code_id', $promo->id)->where('user_id', $client->id)
                ->whereIn('status', PromoRedemption::LIVE)
                ->when($excludeSessionId, fn ($q) => $q->where(fn ($w) => $w->whereNull('therapy_session_id')->orWhere('therapy_session_id', '!=', $excludeSessionId)))
                ->count();
            if ($used >= (int) $promo->per_user_limit) {
                $this->fail('Вы уже использовали этот промокод.');
            }
        }

        $r = $promo->restrictions ?? [];
        if (! empty($r['service_types']) && ! in_array($format, $r['service_types'], true)) {
            $this->fail($format === 'pair' ? 'Промокод не действует для парных сессий.' : 'Промокод действует только для парных сессий.');
        }
        if (! empty($r['psychologist_ids']) && (! $psychologist || ! in_array($psychologist->id, $r['psychologist_ids'], true))) {
            $this->fail('Промокод не действует для выбранного психолога.');
        }
        if (! empty($r['price_category_ids']) && (! $psychologist || ! in_array($psychologist->price_category_id, $r['price_category_ids'], true))) {
            $this->fail('Промокод не действует для этой ценовой категории.');
        }
        if ($promo->min_amount !== null && $price < (int) $promo->min_amount) {
            $this->fail('Промокод действует для сессий от '.Money::format((int) $promo->min_amount).'.');
        }

        $segment = $r['segment'] ?? [];
        if (! empty($segment['new_clients']) && $this->hasPaidSessions($client, $excludeSessionId)) {
            $this->fail('Промокод только для новых клиентов: у вас уже есть оплаченные сессии.');
        }
        if (! empty($segment['registered_after']) && $client->created_at && $client->created_at->lt(CarbonImmutable::parse($segment['registered_after'], config('platform.timezone'))->startOfDay())) {
            $date = CarbonImmutable::parse($segment['registered_after'])->locale('ru')->translatedFormat('j F Y');
            $this->fail("Промокод для клиентов, зарегистрированных с {$date}.");
        }
        if (! empty($segment['min_held_sessions'])) {
            $held = TherapySession::where('client_id', $client->id)->where('status', TherapySession::HELD)->count();
            if ($held < (int) $segment['min_held_sessions']) {
                $this->fail('Промокод для клиентов, у которых проведено не меньше '.(int) $segment['min_held_sessions'].' сессий.');
            }
        }

        if ($promo->type === 'first_session') {
            if ($this->hasPaidSessions($client, $excludeSessionId)) {
                $this->fail('Промокод действует только на первую оплаченную сессию.');
            }
            $otherFirst = PromoRedemption::where('user_id', $client->id)
                ->whereIn('status', PromoRedemption::LIVE)
                ->whereHas('promoCode', fn ($q) => $q->where('type', 'first_session'))
                ->when($excludeSessionId, fn ($q) => $q->where(fn ($w) => $w->whereNull('therapy_session_id')->orWhere('therapy_session_id', '!=', $excludeSessionId)))
                ->exists();
            if ($otherFirst) {
                $this->fail('Скидка на первую сессию уже применена к другой записи.');
            }
        }

        $discount = $promo->discountFor($price);
        if ($discount <= 0) {
            $this->fail('Промокод не даёт скидки для этой сессии.');
        }

        return $discount;
    }

    /** Paid sessions of the client outside corporate programs (BR-PROMO-01, BR-PROMO-06). */
    public function hasPaidSessions(User $client, ?string $excludeSessionId = null): bool
    {
        return TherapySession::where('client_id', $client->id)
            ->whereNotNull('paid_at')
            ->whereNull('corporate_participation_id')
            ->when($excludeSessionId, fn ($q) => $q->whereKeyNot($excludeSessionId))
            ->exists();
    }

    private function assertNotCorporate(bool $corporate): void
    {
        if ($corporate) {
            $this->fail('Промокод не применяется к сессиям по корпоративной программе.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['promo_code' => $message]);
    }
}
