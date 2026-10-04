<?php

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\TherapySession;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * P-REMINDERS (24 h and 1 h by default): reminders to the client, the pair partner and the psychologist.
 * Only the most imminent reminder that is due is sent; larger thresholds already passed are skipped
 * (a booking made 2 h before the start gets only the 1 h reminder). Notification preferences are respected by
 * Notifier (codes containing "reminder").
 */
class ReminderService
{
    public function __construct(private BookingNotifications $notify) {}

    public function send(): int
    {
        $thresholds = array_map('intval', (array) Settings::get('P-REMINDERS'));
        rsort($thresholds);
        if ($thresholds === []) {
            return 0;
        }
        $sent = 0;
        $ids = TherapySession::whereIn('status', [TherapySession::BOOKED, TherapySession::PAID])
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addMinutes($thresholds[0]))
            ->pluck('id');

        foreach ($ids as $id) {
            $minutes = DB::transaction(function () use ($id, $thresholds) {
                $s = TherapySession::whereKey($id)->lockForUpdate()->first();
                if (! $s) {
                    return null;
                }
                $lead = (int) floor(now()->diffInMinutes(CarbonImmutable::parse($s->starts_at)));
                $due = array_values(array_filter($thresholds, fn ($t) => $lead <= $t));
                if ($due === []) {
                    return null;
                }
                $already = array_map('intval', $s->reminders_sent ?? []);
                $current = min($due);
                if (in_array($current, $already, true)) {
                    return null;
                }
                $s->forceFill(['reminders_sent' => array_values(array_unique([...$already, ...$due]))])->save();

                return $current;
            });
            if ($minutes !== null) {
                $this->notify->reminder(TherapySession::findOrFail($id), $minutes);
                $sent++;
            }
        }

        return $sent;
    }
}
