<?php

namespace App\Modules\Schedule\Services;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\SlotHold;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SCHED: free slots of a psychologist (BR-SCHED). Slots start at the beginning of a working interval and
 * follow each other with step = duration + buffer. A slot is free when it fits into a working interval,
 * lies within [now + min lead, now + horizon], and does not overlap (with the buffer) occupying sessions,
 * active holds, vacations or blocked dates. All times are returned in UTC.
 */
class SlotService
{
    public function duration(string $format): int
    {
        return $format === 'pair' ? Settings::int('P-SESSION-DURATION-PAIR') : Settings::int('P-SESSION-DURATION-IND');
    }

    public function buffer(Psychologist $p): int
    {
        return max(Settings::int('P-BUFFER'), (int) $p->buffer_minutes);
    }

    /**
     * @param  array{ignore_session_id?: string|null, ignore_hold_id?: string|null, ignore_lead?: bool}  $options
     * @return list<CarbonImmutable>
     */
    public function availableSlots(Psychologist $p, string $format, CarbonImmutable $from, CarbonImmutable $to, array $options = []): array
    {
        $now = CarbonImmutable::now();
        $duration = $this->duration($format);
        $buffer = $this->buffer($p);
        $minLead = ($options['ignore_lead'] ?? false) ? 0 : max(Settings::int('P-BOOK-MIN-LEAD'), (int) $p->min_lead_minutes);
        $horizonDays = $p->horizon_days ? min(Settings::int('P-BOOK-HORIZON'), (int) $p->horizon_days) : Settings::int('P-BOOK-HORIZON');

        $windowStart = $from->max($now->addMinutes($minLead));
        $windowEnd = $to->min($now->addDays($horizonDays));
        if ($windowStart > $windowEnd) {
            return [];
        }

        $blocked = $this->blockedIntervals($p, $windowStart->subDay(), $windowEnd->addDay(), $buffer, $options);
        $intervals = $p->scheduleIntervals()->get()->groupBy('weekday');
        $tz = $p->timezone ?: config('platform.timezone');

        $slots = [];
        $day = $windowStart->setTimezone($tz)->startOfDay();
        $lastDay = $windowEnd->setTimezone($tz)->startOfDay();
        while ($day <= $lastDay) {
            foreach ($intervals->get($day->isoWeekday(), collect()) as $interval) {
                $cursor = CarbonImmutable::parse($day->toDateString().' '.$interval->starts_at, $tz);
                $end = CarbonImmutable::parse($day->toDateString().' '.$interval->ends_at, $tz);
                while ($cursor->addMinutes($duration) <= $end) {
                    $slotEnd = $cursor->addMinutes($duration);
                    if ($cursor >= $windowStart && $cursor <= $windowEnd && ! $this->overlaps($blocked, $cursor, $slotEnd)) {
                        $slots[] = $cursor->utc();
                    }
                    $cursor = $cursor->addMinutes($duration + $buffer);
                }
            }
            $day = $day->addDay();
        }

        usort($slots, fn ($a, $b) => $a <=> $b);

        return array_values(array_unique($slots, SORT_REGULAR));
    }

    public function isAvailable(Psychologist $p, CarbonImmutable $start, string $format, array $options = []): bool
    {
        $start = $start->utc()->startOfMinute();
        foreach ($this->availableSlots($p, $format, $start, $start, $options) as $slot) {
            if ($slot->equalTo($start)) {
                return true;
            }
        }

        return false;
    }

    /** Throws a validation error unless the psychologist accepts bookings and the slot is free. */
    public function assertBookable(Psychologist $p, CarbonImmutable $start, string $format, array $options = []): void
    {
        if (! $p->isBookable()) {
            throw ValidationException::withMessages(['psychologist' => 'Сейчас к этому психологу нельзя записаться.']);
        }
        if ($format === 'pair' && ! $p->works_pair) {
            throw ValidationException::withMessages(['format' => 'Психолог не проводит парные сессии.']);
        }
        if (! $this->isAvailable($p, $start, $format, $options)) {
            throw ValidationException::withMessages(['starts_at' => 'Это время уже занято или недоступно. Выберите другое.']);
        }
    }

    /** P-SLOT-HOLD: keep the slot while the client registers and binds a card. */
    public function hold(Psychologist $p, CarbonImmutable $start, string $format, ?User $user = null, ?string $guestToken = null): SlotHold
    {
        $this->assertBookable($p, $start, $format);
        if ($user) {
            SlotHold::where('user_id', $user->id)->where('psychologist_id', $p->id)->delete();
        }

        return SlotHold::create([
            'psychologist_id' => $p->id,
            'user_id' => $user?->id,
            'guest_token' => $user ? null : ($guestToken ?? Str::random(40)),
            'format' => $format,
            'starts_at' => $start->utc(),
            'ends_at' => $start->utc()->addMinutes($this->duration($format)),
            'expires_at' => now()->addMinutes(Settings::int('P-SLOT-HOLD')),
        ]);
    }

    public function release(SlotHold $hold): void
    {
        $hold->delete();
    }

    /** Nearest free slot within P-MATCH-AVAILABILITY-DAYS (or the given number of days). */
    public function nearest(Psychologist $p, string $format = 'individual', ?int $days = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $slots = $this->availableSlots($p, $format, $now, $now->addDays($days ?? Settings::int('P-BOOK-HORIZON')));

        return $slots[0] ?? null;
    }

    /** @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}> */
    private function blockedIntervals(Psychologist $p, CarbonImmutable $from, CarbonImmutable $to, int $buffer, array $options): Collection
    {
        $sessions = TherapySession::query()
            ->where('psychologist_id', $p->id)
            ->occupying()
            ->when($options['ignore_session_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($s) => [CarbonImmutable::parse($s->starts_at)->subMinutes($buffer), CarbonImmutable::parse($s->ends_at)->addMinutes($buffer)]);

        $holds = SlotHold::query()
            ->where('psychologist_id', $p->id)
            ->where('expires_at', '>', now())
            ->when($options['ignore_hold_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($h) => [CarbonImmutable::parse($h->starts_at)->subMinutes($buffer), CarbonImmutable::parse($h->ends_at)->addMinutes($buffer)]);

        $exceptions = ScheduleException::query()
            ->where('psychologist_id', $p->id)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->map(fn ($e) => [CarbonImmutable::parse($e->starts_at), CarbonImmutable::parse($e->ends_at)]);

        return $sessions->concat($holds)->concat($exceptions)->values();
    }

    private function overlaps(Collection $blocked, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $blocked->contains(fn ($b) => $start < $b[1] && $end > $b[0]);
    }
}
