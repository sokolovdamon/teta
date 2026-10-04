<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Models\ScheduleInterval;
use Carbon\CarbonImmutable;

/** Shared helpers for tests of every stream. */
trait CreatesPsychologists
{
    /**
     * Approved, published, active psychologist with a weekly schedule.
     *
     * @param  list<array{0: int, 1: string, 2: string}>|null  $intervals  [weekday, from, to] in the psychologist's timezone
     */
    protected function makePsychologist(array $overrides = [], ?array $intervals = null): Psychologist
    {
        $user = User::factory()->withRole('psychologist')->create();
        $price = $overrides['price_individual'] ?? 400000;
        $p = new Psychologist([
            'user_id' => $user->id,
            'slug' => Psychologist::uniqueSlug($user->name.' '.$user->last_name),
            'first_name' => $user->name,
            'last_name' => $user->last_name,
            'gender' => 'female',
            'experience_years' => 7,
            'price_individual' => $price,
            'price_pair' => $overrides['price_pair'] ?? null,
            'works_pair' => isset($overrides['price_pair']),
            'price_category_id' => PriceCategory::forPrice($price)?->id,
            'timezone' => 'Europe/Moscow',
            'is_published' => true,
            'published_at' => now()->subMonths(2),
            ...collect($overrides)->except(['qualification_status', 'activity_status', 'work_status'])->all(),
        ]);
        $p->forceFill([
            'qualification_status' => $overrides['qualification_status'] ?? 'approved',
            'qualified_at' => $overrides['qualified_at'] ?? now()->subMonths(3),
            'activity_status' => array_key_exists('activity_status', $overrides) ? $overrides['activity_status'] : 'active_met',
            'work_status' => $overrides['work_status'] ?? 'active',
        ])->save();

        foreach ($intervals ?? [[1, '10:00', '20:00'], [2, '10:00', '20:00'], [3, '10:00', '20:00'], [4, '10:00', '20:00'], [5, '10:00', '20:00'], [6, '10:00', '20:00'], [7, '10:00', '20:00']] as [$day, $from, $to]) {
            ScheduleInterval::create(['psychologist_id' => $p->id, 'weekday' => $day, 'starts_at' => $from, 'ends_at' => $to]);
        }

        return $p->fresh();
    }

    /** A session row in the given status (no side effects). */
    protected function makeSession(Psychologist $p, User $client, CarbonImmutable $start, string $status = TherapySession::BOOKED, array $attrs = []): TherapySession
    {
        $duration = ($attrs['format'] ?? 'individual') === 'pair' ? 90 : 50;
        $session = new TherapySession([
            'client_id' => $client->id,
            'psychologist_id' => $p->id,
            'format' => 'individual',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($duration),
            'duration_min' => $duration,
            'price' => $p->price_individual ?? 400000,
            'amount_due' => $p->price_individual ?? 400000,
            ...$attrs,
        ]);
        $session->forceFill(['status' => $status])->save();

        return $session;
    }
}
