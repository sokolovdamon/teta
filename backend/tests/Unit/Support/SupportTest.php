<?php

namespace Tests\Unit\Support;

use App\Support\Calendar\WorkingDays;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SupportTest extends TestCase
{
    public function test_money_share_and_format(): void
    {
        $this->assertSame(245000, Money::share(350000, 70));
        $this->assertSame(2333, Money::share(3333, 70));
        $this->assertSame('3 500 ₽', Money::format(350000));
        $this->assertSame('35,50 ₽', Money::format(3550));
    }

    public function test_working_days_skip_weekends_and_holidays(): void
    {
        // 2026-12-30 is Wednesday; 31 Dec 2026 is a day off, 1–8 Jan 2027 holidays and weekends follow.
        $due = WorkingDays::add(CarbonImmutable::parse('2026-12-30'), 1);
        $this->assertSame('2027-01-11', $due->toDateString());

        // 14 working days from Monday 2026-10-05 is Friday 2026-10-23.
        $this->assertSame('2026-10-23', WorkingDays::add(CarbonImmutable::parse('2026-10-05'), 14)->toDateString());
    }
}
