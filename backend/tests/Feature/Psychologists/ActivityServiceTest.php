<?php

namespace Tests\Feature\Psychologists;

use App\Modules\Psychologists\Services\ActivityService;
use App\Modules\Rbac\Services\RbacService;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

class ActivityServiceTest extends TestCase
{
    use CreatesPsychologists;

    public function test_grace_period_then_requirement_and_inactivation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'Europe/Moscow'));
        $p = $this->makePsychologist(['activity_status' => null, 'qualified_at' => now()]);
        $svc = app(ActivityService::class);
        $svc->onQualified($p);
        $this->assertSame('grace', $p->fresh()->activity_status);
        $this->assertTrue($svc->payoutAllowed($p->user));

        // October is the first full month: requirement applies, not met yet.
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:30', 'Europe/Moscow'));
        $svc->monthlyRollover();
        $this->assertSame('active_not_met', $p->fresh()->activity_status);
        $this->assertFalse($svc->payoutAllowed($p->fresh()->user));

        // No supervision in October → inactive on 1 November.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 00:30', 'Europe/Moscow'));
        $svc->monthlyRollover();
        $p = $p->fresh();
        $this->assertSame('inactive', $p->activity_status);
        $this->assertFalse($p->isBookable());
        $this->assertDatabaseHas('domain_events', ['name' => 'psy.activity.changed', 'aggregate_id' => $p->id]);

        // Supervision held in November → active again, payouts allowed.
        $svc->markMonthMet($p);
        $p = $p->fresh();
        $this->assertSame('active_met', $p->activity_status);
        $this->assertTrue($p->isBookable());
        $this->assertTrue($svc->payoutAllowed($p->user));

        // New month resets to "not met".
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30', 'Europe/Moscow'));
        $svc->monthlyRollover();
        $this->assertSame('active_not_met', $p->fresh()->activity_status);
    }

    public function test_supervisor_without_clients_is_exempt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00', 'Europe/Moscow'));
        $p = $this->makePsychologist(['qualified_at' => now()->subMonths(4), 'activity_status' => 'active_not_met']);
        app(RbacService::class)->assignRole($p->user, 'supervisor');
        $p = $p->fresh();

        $this->assertTrue(app(ActivityService::class)->payoutAllowed($p->user));
    }
}
