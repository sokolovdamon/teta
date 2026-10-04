<?php

namespace App\Modules\Schedule\Services;

use App\Modules\Booking\Models\TherapySession;
use App\Modules\Catalog\Services\NearestSlots;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Services\PublicationService;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleInterval;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRO-03, SCHED: weekly working intervals in the psychologist's timezone (gaps are breaks), vacations and blocked
 * dates, personal limits that may only TIGHTEN the platform ones (P-BOOK-MIN-LEAD, P-BOOK-HORIZON, P-BUFFER).
 * Times are stored in UTC (BR-SCHED-01). A vacation cannot cover booked sessions: they must be rescheduled or
 * cancelled first (BR-SCHED-04). A change re-evaluates publication (BR-PSY-04) and drops the cached nearest slot.
 */
class ScheduleService
{
    public const MAX_INTERVALS_PER_DAY = 8;

    public const MAX_EXCEPTION_DAYS = 366;

    public function __construct(private SlotService $slots, private PublicationService $publication) {}

    public function overview(Psychologist $p): array
    {
        return [
            'timezone' => $p->timezone,
            'intervals' => $p->scheduleIntervals()->orderBy('weekday')->orderBy('starts_at')->get()->map(fn ($i) => $this->intervalRow($i))->values(),
            'exceptions' => $p->scheduleExceptions()->where('ends_at', '>', now()->subDays(30))->orderBy('starts_at')->get()
                ->map(fn ($e) => $this->exceptionRow($e))->values(),
            'settings' => [
                'min_lead_minutes' => $p->min_lead_minutes,
                'horizon_days' => $p->horizon_days,
                'buffer_minutes' => $p->buffer_minutes,
            ],
            'platform' => [
                'min_lead_minutes' => Settings::int('P-BOOK-MIN-LEAD'),
                'horizon_days' => Settings::int('P-BOOK-HORIZON'),
                'buffer_minutes' => Settings::int('P-BUFFER'),
                'duration_individual' => Settings::int('P-SESSION-DURATION-IND'),
                'duration_pair' => Settings::int('P-SESSION-DURATION-PAIR'),
            ],
            'effective' => [
                'min_lead_minutes' => max(Settings::int('P-BOOK-MIN-LEAD'), (int) $p->min_lead_minutes),
                'horizon_days' => $p->horizon_days ? min(Settings::int('P-BOOK-HORIZON'), (int) $p->horizon_days) : Settings::int('P-BOOK-HORIZON'),
                'buffer_minutes' => $this->slots->buffer($p),
            ],
            'formats' => ['works_individual' => (bool) $p->works_individual, 'works_pair' => (bool) $p->works_pair],
        ];
    }

    /**
     * Replace the whole weekly set (the editor saves all days at once).
     *
     * @param  list<array{weekday: int, starts_at: string, ends_at: string}>  $intervals
     */
    public function replaceIntervals(Psychologist $p, array $intervals): void
    {
        $normalized = $this->validateSet($p, $intervals);
        DB::transaction(function () use ($p, $normalized) {
            $p->scheduleIntervals()->delete();
            foreach ($normalized as $i) {
                ScheduleInterval::create(['psychologist_id' => $p->id, ...$i]);
            }
            $this->changed($p);
        });
    }

    /** @param  array{weekday: int, starts_at: string, ends_at: string}  $data */
    public function addInterval(Psychologist $p, array $data): ScheduleInterval
    {
        $existing = $p->scheduleIntervals()->get()->map(fn ($i) => $this->intervalRow($i))->all();
        $normalized = $this->validateSet($p, [...$existing, $data], count($existing));

        return DB::transaction(function () use ($p, $normalized) {
            $interval = ScheduleInterval::create(['psychologist_id' => $p->id, ...end($normalized)]);
            $this->changed($p);

            return $interval;
        });
    }

    /** @param  array{weekday?: int, starts_at?: string, ends_at?: string}  $data */
    public function updateInterval(Psychologist $p, ScheduleInterval $interval, array $data): ScheduleInterval
    {
        $rows = $p->scheduleIntervals()->get()->map(fn ($i) => $i->id === $interval->id ? [...$this->intervalRow($i), ...$data] : $this->intervalRow($i))->all();
        $index = array_search($interval->id, array_column($rows, 'id'), true);
        $normalized = $this->validateSet($p, $rows, $index === false ? null : $index);

        DB::transaction(function () use ($p, $interval, $normalized, $index) {
            $interval->update($normalized[$index]);
            $this->changed($p);
        });

        return $interval->fresh();
    }

    public function deleteInterval(Psychologist $p, ScheduleInterval $interval): void
    {
        DB::transaction(function () use ($p, $interval) {
            $interval->delete();
            $this->changed($p);
        });
    }

    /** @param  array{kind: string, starts_at: string, ends_at: string, comment?: string|null}  $data */
    public function addException(Psychologist $p, array $data): ScheduleException
    {
        $start = CarbonImmutable::parse($data['starts_at'])->utc();
        $end = CarbonImmutable::parse($data['ends_at'])->utc();
        if ($end <= $start) {
            throw ValidationException::withMessages(['ends_at' => 'Окончание должно быть позже начала.']);
        }
        if ($end <= CarbonImmutable::now()) {
            throw ValidationException::withMessages(['ends_at' => 'Период уже прошёл.']);
        }
        if ($start->diffInDays($end, true) > self::MAX_EXCEPTION_DAYS) {
            throw ValidationException::withMessages(['ends_at' => 'Период не может быть длиннее года.']);
        }

        $conflicts = $this->sessionsWithin($p, $start, $end);
        if ($conflicts->isNotEmpty()) {
            $error = ValidationException::withMessages([
                'starts_at' => 'На этот период уже есть записи ('.$conflicts->count().'). Сначала перенесите или отмените их — клиенты получат уведомление.',
            ]);
            $error->response = response()->json([
                'message' => $error->getMessage(),
                'errors' => $error->errors(),
                'sessions' => $conflicts->map(fn ($s) => [
                    'id' => $s->id, 'starts_at' => CarbonImmutable::parse($s->starts_at)->toIso8601ZuluString(), 'format' => $s->format,
                ])->values(),
            ], 422);
            throw $error;
        }

        return DB::transaction(function () use ($p, $data, $start, $end) {
            $exception = ScheduleException::create([
                'psychologist_id' => $p->id,
                'kind' => $data['kind'],
                'starts_at' => $start,
                'ends_at' => $end,
                'comment' => $data['comment'] ?? null,
            ]);
            $this->changed($p);

            return $exception;
        });
    }

    public function deleteException(Psychologist $p, ScheduleException $exception): void
    {
        DB::transaction(function () use ($p, $exception) {
            $exception->delete();
            $this->changed($p);
        });
    }

    /** @param  array{timezone?: string, min_lead_minutes?: int|null, horizon_days?: int|null, buffer_minutes?: int|null}  $data */
    public function updateSettings(Psychologist $p, array $data): void
    {
        $errors = [];
        if (array_key_exists('min_lead_minutes', $data) && $data['min_lead_minutes'] !== null && $data['min_lead_minutes'] < Settings::int('P-BOOK-MIN-LEAD')) {
            $errors['min_lead_minutes'] = 'Можно только увеличить минимальный срок записи платформы ('.Settings::int('P-BOOK-MIN-LEAD').' мин).';
        }
        if (array_key_exists('horizon_days', $data) && $data['horizon_days'] !== null && $data['horizon_days'] > Settings::int('P-BOOK-HORIZON')) {
            $errors['horizon_days'] = 'Горизонт записи не может быть больше, чем у платформы ('.Settings::int('P-BOOK-HORIZON').' дн.).';
        }
        if (array_key_exists('buffer_minutes', $data) && $data['buffer_minutes'] !== null && $data['buffer_minutes'] < Settings::int('P-BUFFER')) {
            $errors['buffer_minutes'] = 'Перерыв не может быть короче, чем у платформы ('.Settings::int('P-BUFFER').' мин).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($p, $data) {
            $p->forceFill(array_intersect_key($data, array_flip(['timezone', 'min_lead_minutes', 'horizon_days', 'buffer_minutes'])))->save();
            $this->changed($p);
        });
    }

    /** Free slots the clients will see (UTC). */
    public function preview(Psychologist $p, string $format, int $days): array
    {
        $now = CarbonImmutable::now();

        return array_map(fn (CarbonImmutable $s) => $s->toIso8601ZuluString(), $this->slots->availableSlots($p, $format, $now, $now->addDays($days)));
    }

    public function intervalRow(ScheduleInterval $i): array
    {
        return [
            'id' => $i->id,
            'weekday' => (int) $i->weekday,
            'starts_at' => substr((string) $i->starts_at, 0, 5),
            'ends_at' => substr((string) $i->ends_at, 0, 5),
        ];
    }

    public function exceptionRow(ScheduleException $e): array
    {
        return [
            'id' => $e->id,
            'kind' => $e->kind,
            'starts_at' => $e->starts_at->toIso8601ZuluString(),
            'ends_at' => $e->ends_at->toIso8601ZuluString(),
            'comment' => $e->comment,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{weekday: int, starts_at: string, ends_at: string}>
     */
    private function validateSet(Psychologist $p, array $rows, ?int $only = null): array
    {
        $durations = array_filter([
            $p->works_individual ? Settings::int('P-SESSION-DURATION-IND') : null,
            $p->works_pair ? Settings::int('P-SESSION-DURATION-PAIR') : null,
        ]) ?: [Settings::int('P-SESSION-DURATION-IND')];
        $minLength = min($durations);

        $errors = [];
        $normalized = [];
        foreach (array_values($rows) as $n => $row) {
            $key = $only === null ? "intervals.{$n}" : 'interval';
            $weekday = (int) ($row['weekday'] ?? 0);
            $from = $this->minutes((string) ($row['starts_at'] ?? ''));
            $to = $this->minutes((string) ($row['ends_at'] ?? ''));
            if ($weekday < 1 || $weekday > 7) {
                $errors["{$key}.weekday"] = 'День недели — от 1 (понедельник) до 7 (воскресенье).';
            } elseif ($from === null || $to === null || $from >= 24 * 60) {
                $errors["{$key}.starts_at"] = 'Время в формате ЧЧ:ММ.';
            } elseif ($to <= $from) {
                $errors["{$key}.ends_at"] = 'Конец интервала должен быть позже начала.';
            } elseif ($to - $from < $minLength) {
                $errors["{$key}.ends_at"] = "Интервал короче сессии: нужно не меньше {$minLength} мин.";
            }
            $normalized[] = ['weekday' => $weekday, 'starts_at' => $this->format($from ?? 0), 'ends_at' => $this->format($to ?? 0), '_from' => $from, '_to' => $to];
        }

        if (! $errors) {
            $byDay = collect($normalized)->groupBy('weekday');
            foreach ($byDay as $day => $list) {
                if ($list->count() > self::MAX_INTERVALS_PER_DAY) {
                    $errors['intervals'] = 'Слишком много интервалов в один день.';
                    break;
                }
                $sorted = $list->sortBy('_from')->values();
                for ($k = 1; $k < $sorted->count(); $k++) {
                    if ($sorted[$k]['_from'] < $sorted[$k - 1]['_to']) {
                        $errors[$only === null ? 'intervals' : 'interval'] = 'Интервалы в '.self::weekdayName((int) $day).' пересекаются.';
                        break 2;
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_map(fn ($i) => ['weekday' => $i['weekday'], 'starts_at' => $i['starts_at'], 'ends_at' => $i['ends_at']], $normalized);
    }

    private function minutes(string $hhmm): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::00)?$/', $hhmm, $m)) {
            return null;
        }
        [$h, $min] = [(int) $m[1], (int) $m[2]];
        if ($min > 59 || $h > 24 || ($h === 24 && $min > 0)) {
            return null;
        }

        return $h * 60 + $min;
    }

    private function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function weekdayName(int $day): string
    {
        return ['', 'понедельник', 'вторник', 'среду', 'четверг', 'пятницу', 'субботу', 'воскресенье'][$day] ?? '';
    }

    /** @return Collection<int, TherapySession> */
    private function sessionsWithin(Psychologist $p, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return TherapySession::query()
            ->where('psychologist_id', $p->id)
            ->occupying()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'format']);
    }

    private function changed(Psychologist $p): void
    {
        NearestSlots::forget($p->id);
        $this->publication->sync($p->refresh());
    }
}
