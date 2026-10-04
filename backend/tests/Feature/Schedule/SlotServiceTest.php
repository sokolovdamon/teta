<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Services\SlotService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

class SlotServiceTest extends TestCase
{
    use CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2026-10-05 08:00 MSK.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
        $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
    }

    private function slots(...$args): array
    {
        return array_map(fn ($s) => $s->setTimezone('Europe/Moscow')->format('Y-m-d H:i'), app(SlotService::class)->availableSlots(...$args));
    }

    public function test_slots_follow_duration_and_buffer_inside_intervals(): void
    {
        $p = $this->makePsychologist(intervals: [[2, '10:00', '14:00']]);
        $from = CarbonImmutable::parse('2026-10-06 00:00', 'Europe/Moscow');
        $this->assertSame(
            ['2026-10-06 10:00', '2026-10-06 11:00', '2026-10-06 12:00', '2026-10-06 13:00'],
            $this->slots($p, 'individual', $from, $from->endOfDay()),
        );
        // Pair session: 90 min + 10 min buffer.
        $this->assertSame(['2026-10-06 10:00', '2026-10-06 11:40'], $this->slots($p, 'pair', $from, $from->endOfDay()));
    }

    public function test_min_lead_and_horizon(): void
    {
        $p = $this->makePsychologist(intervals: [[1, '08:00', '14:00']]);
        $today = CarbonImmutable::parse('2026-10-05 00:00', 'Europe/Moscow');
        // now 08:00 MSK + 3 h lead → first slot not before 11:00.
        $this->assertSame(['2026-10-05 11:00', '2026-10-05 12:00'], array_slice($this->slots($p, 'individual', $today, $today->endOfDay()), 0, 2));
        $far = CarbonImmutable::parse('2026-12-01', 'Europe/Moscow');
        $this->assertSame([], $this->slots($p, 'individual', $far, $far->addDays(7)));
    }

    public function test_sessions_holds_and_exceptions_block_slots(): void
    {
        $p = $this->makePsychologist(intervals: [[2, '10:00', '14:00']]);
        $client = User::factory()->withRole('client')->create();
        $day = CarbonImmutable::parse('2026-10-06 00:00', 'Europe/Moscow');

        $this->makeSession($p, $client, CarbonImmutable::parse('2026-10-06 11:00', 'Europe/Moscow'));
        app(SlotService::class)->hold($p, CarbonImmutable::parse('2026-10-06 13:00', 'Europe/Moscow'), 'individual', $client);
        $this->assertSame(['2026-10-06 10:00', '2026-10-06 12:00'], $this->slots($p, 'individual', $day, $day->endOfDay()));

        ScheduleException::create(['psychologist_id' => $p->id, 'kind' => 'vacation', 'starts_at' => $day, 'ends_at' => $day->endOfDay()]);
        $this->assertSame([], $this->slots($p, 'individual', $day, $day->endOfDay()));
    }

    public function test_cancelled_sessions_free_the_slot_and_reschedule_can_ignore_own_session(): void
    {
        $p = $this->makePsychologist(intervals: [[2, '10:00', '12:00']]);
        $client = User::factory()->withRole('client')->create();
        $start = CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Moscow');
        $s = $this->makeSession($p, $client, $start, 'paid');

        $svc = app(SlotService::class);
        $this->assertFalse($svc->isAvailable($p, $start, 'individual'));
        $this->assertTrue($svc->isAvailable($p, $start, 'individual', ['ignore_session_id' => $s->id]));
        $s->forceFill(['status' => 'cancelled_by_client'])->save();
        $this->assertTrue($svc->isAvailable($p, $start, 'individual'));
    }

    public function test_assert_bookable_rejects_inactive_psychologist(): void
    {
        $p = $this->makePsychologist(['activity_status' => 'inactive'], [[2, '10:00', '12:00']]);
        $this->expectException(ValidationException::class);
        app(SlotService::class)->assertBookable($p, CarbonImmutable::parse('2026-10-06 10:00', 'Europe/Moscow'), 'individual');
    }

    public function test_psychologist_timezone_is_respected(): void
    {
        $p = $this->makePsychologist(['timezone' => 'Asia/Vladivostok'], [[2, '10:00', '11:00']]);
        $day = CarbonImmutable::parse('2026-10-05 00:00', 'UTC');
        // 10:00 Vladivostok (UTC+10) = 03:00 MSK.
        $this->assertSame(['2026-10-06 03:00'], $this->slots($p, 'individual', $day, $day->addDays(2)));
    }
}
