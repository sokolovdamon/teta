<?php

namespace Tests\Feature\Booking;

use App\Models\User;
use App\Modules\Booking\Models\QualityIncident;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** SEQ-05 and SEQ-07: psychologist's cancel and no-show with the client's choice, outcomes, reminders. */
class SessionOutcomeTest extends TestCase
{
    use BookingFixtures;

    private Psychologist $psy;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
        $this->psy = $this->makePsychologist(['price_individual' => 400000]);
        $this->clientUser = $this->client();
        $this->bindCard($this->clientUser);
    }

    private function paid(string $start = '2026-10-08 14:00', int $balance = 0): TherapySession
    {
        if ($balance) {
            $this->credit($this->clientUser, $balance, certificate: true);
        }
        $session = $this->book($this->clientUser, $this->psy, $start);
        $charge = $session->charge_due_at;
        $this->travelTo($charge);
        CarbonImmutable::setTestNow($charge);
        $this->artisan('pay:dispatch-due-charges');

        return $session->fresh();
    }

    private function asPsychologist(): User
    {
        return $this->actAs(User::findOrFail($this->psy->user_id));
    }

    public function test_psychologist_cancel_of_paid_session_then_client_chooses_refund(): void
    {
        $session = $this->paid(balance: 100000);
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$session->id}/cancel", ['reason' => 'Заболела'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled_by_psy');
        $session->refresh();
        $this->assertSame('pending', $session->client_choice);
        // Cancelled less than 12 hours before the start: a quality incident.
        $this->assertSame(1, QualityIncident::where('psychologist_id', $this->psy->id)->count());
        $this->assertTrue(UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'book.psy_cancelled_choice')->exists());

        $this->actAs($this->clientUser);
        $this->getJson('/api/v1/booking/sessions')->assertOk()->assertJsonPath('data.0.actions.choose', true);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/choice", ['choice' => 'refund'])->assertOk()->assertJsonPath('data.client_choice', 'refund');

        $balance = $this->balanceOf($this->clientUser);
        $this->assertSame(400000, $balance['available']);
        // The part paid with certificate funds comes back as certificate funds.
        $this->assertSame(100000, $balance['certificate_available']);
        $this->assertSame(300000, $balance['withdrawable']);
        $this->assertSame(['psy_cancel'], ClientBalanceOperation::where('type', 'credit')->where('reason', '!=', 'certificate')->pluck('reason')->unique()->values()->all());
        $this->assertCount(1, $this->events('book.session.refund_chosen'));
        $this->postJson("/api/v1/booking/sessions/{$session->id}/choice", ['choice' => 'refund'])->assertStatus(409);
    }

    public function test_free_reschedule_after_psychologist_cancel_moves_the_payment(): void
    {
        $session = $this->paid();
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$session->id}/cancel", ['reason' => 'Форс-мажор'])->assertOk();

        $this->actAs($this->clientUser);
        $res = $this->postJson("/api/v1/booking/sessions/{$session->id}/choice", ['choice' => 'reschedule', 'starts_at' => $this->msk('2026-10-10 12:00')->toIso8601String()])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $new = TherapySession::findOrFail($res->json('data.id'));
        $this->assertSame($session->id, $new->rescheduled_from_id);
        $this->assertSame($session->payment_id, $new->payment_id);
        $this->assertSame(400000, (int) $new->amount_charged);
        $this->assertSame('reschedule', $session->fresh()->client_choice);
        $this->assertSame(0, $session->fresh()->retainedAmount());
        $this->assertSame(0, $this->balanceOf($this->clientUser)['available']);
        $this->assertSame('free_reschedule', $this->events('book.session.booked')->last()->payload['payment_mode']);
    }

    public function test_unpaid_session_cancelled_by_psychologist_is_free_and_choice_deadline_refunds_automatically(): void
    {
        $unpaid = $this->book($this->clientUser, $this->psy, '2026-10-12 14:00');
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$unpaid->id}/cancel", ['reason' => 'Отпуск'])->assertOk();
        $this->assertNull($unpaid->fresh()->client_choice);
        $this->assertSame(0, QualityIncident::count());
        $this->assertTrue(UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'book.psy_cancelled_free')->exists());

        $paid = $this->paid();
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$paid->id}/cancel", ['reason' => 'Заболела'])->assertOk();
        $this->moveTo('2026-10-08 14:01');
        $this->artisan('book:advance');
        $this->assertSame('refund', $paid->fresh()->client_choice);
        $this->assertSame(400000, $this->balanceOf($this->clientUser)['available']);
    }

    public function test_quality_incident_threshold_notifies_admins(): void
    {
        $admin = $this->userWithRole('admin');
        foreach (['2026-10-05 15:00', '2026-10-05 17:00'] as $at) {
            $s = $this->book($this->clientUser, $this->psy, $at);
            $this->asPsychologist();
            $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/cancel", ['reason' => 'Не смогу'])->assertOk();
        }
        $this->assertSame(2, QualityIncident::count());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('template_code', 'book.quality_threshold')->exists());
        $this->assertCount(1, $this->events('book.quality.threshold_reached'));
    }

    public function test_session_starts_and_outcome_is_resolved_from_the_session_log(): void
    {
        $cases = [
            ['held', ['client_joined_at' => '14:01', 'psychologist_joined_at' => '13:58', 'joint_duration_sec' => 45 * 60]],
            ['psy_no_show', ['client_joined_at' => '14:00', 'psychologist_joined_at' => null, 'joint_duration_sec' => 0]],
            ['client_no_show', ['client_joined_at' => '14:30', 'psychologist_joined_at' => '13:59', 'joint_duration_sec' => 300]],
            ['tech_issue', ['client_joined_at' => '14:02', 'psychologist_joined_at' => '14:00', 'joint_duration_sec' => 600]],
        ];
        $sessions = [];
        foreach ($cases as $i => [$expected, $log]) {
            $day = 8 + $i;
            $s = $this->paid("2026-10-{$day} 14:00");
            $sessions[] = [$s, $expected, $log, $day];
        }
        foreach ($sessions as [$s, $expected, $log, $day]) {
            $this->moveTo("2026-10-{$day} 14:00");
            $this->artisan('book:advance');
            $this->assertSame('in_progress', $s->fresh()->status);
            $s->forceFill([
                'client_joined_at' => $log['client_joined_at'] ? $this->msk("2026-10-{$day} {$log['client_joined_at']}") : null,
                'psychologist_joined_at' => $log['psychologist_joined_at'] ? $this->msk("2026-10-{$day} {$log['psychologist_joined_at']}") : null,
                'joint_duration_sec' => $log['joint_duration_sec'],
            ])->save();
            // Before P-OUTCOME-DEADLINE nothing is decided automatically.
            $this->moveTo("2026-10-{$day} 20:00");
            $this->artisan('book:advance');
            $this->assertSame('in_progress', $s->fresh()->status);
            $this->moveTo('2026-10-'.($day + 1).' 14:00');
            $this->artisan('book:advance');
            $s->refresh();
            $this->assertSame($expected, $s->status, $expected);
            $this->assertSame('auto', $s->outcome_source);
            $this->assertSame(in_array($expected, ['psy_no_show', 'tech_issue'], true) ? 'pending' : null, $s->client_choice, $expected);
        }
        // The no-show choice was not made in time: refunded to the balance automatically.
        $this->assertSame('refund', $sessions[1][0]->fresh()->client_choice);
        $this->assertTrue(UserNotification::where('template_code', 'book.psy_no_show_recorded')->exists());
        $held = $this->events('book.session.held')->first();
        $this->assertSame(400000, $held->payload['price']);
        $this->assertSame(400000, $held->payload['amount_charged']);
        $this->assertSame('held', $held->payload['kind']);
        $this->assertTrue(UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'book.client_no_show')->exists());
    }

    public function test_psychologist_marks_outcome_with_rules(): void
    {
        $s = $this->paid();
        $this->moveTo('2026-10-08 14:05');
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/outcome", ['outcome' => 'held'])->assertStatus(422)->assertJsonPath('code', 'zero_duration');
        $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/outcome", ['outcome' => 'client_no_show'])->assertStatus(422)->assertJsonPath('code', 'too_early');
        $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/outcome", ['outcome' => 'psy_no_show'])->assertStatus(422);

        $this->moveTo('2026-10-08 14:16');
        $this->postJson("/api/v1/booking/pro/sessions/{$s->id}/outcome", ['outcome' => 'client_no_show'])->assertOk()->assertJsonPath('data.status', 'client_no_show');
        $this->assertSame('psychologist', $s->fresh()->outcome_source);

        $s2 = $this->paid('2026-10-09 14:00');
        $this->moveTo('2026-10-09 14:40');
        $s2->forceFill(['joint_duration_sec' => 2000])->save();
        $this->asPsychologist();
        $this->postJson("/api/v1/booking/pro/sessions/{$s2->id}/outcome", ['outcome' => 'held'])->assertOk()->assertJsonPath('data.status', 'held');
    }

    public function test_reminders_are_sent_once_and_respect_preferences(): void
    {
        Mail::fake();
        NotificationPreference::updateOrCreate(['user_id' => $this->clientUser->id], ['session_reminders' => false]);
        $this->book($this->clientUser, $this->psy, '2026-10-06 12:00');
        $this->moveTo('2026-10-05 12:05');
        $this->artisan('book:send-reminders');
        $this->artisan('book:send-reminders');
        $this->assertSame(1, UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'book.session_reminder')->count());
        $this->assertSame(1, UserNotification::where('user_id', $this->psy->user_id)->where('template_code', 'book.psy_session_reminder')->count());
        // The client switched reminder emails off: only the notification centre entry.
        Mail::assertNotSent(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, 'Напоминание') && $m->hasTo($this->clientUser->email));
        Mail::assertSent(TemplatedMail::class, fn ($m) => str_contains($m->subjectLine, 'Напоминание') && $m->hasTo(User::findOrFail($this->psy->user_id)->email));

        $this->moveTo('2026-10-06 11:01');
        $this->artisan('book:send-reminders');
        $this->assertSame(2, UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'book.session_reminder')->count());

        // A booking made 3 hours before the start gets only the 1 h reminder.
        $other = $this->client();
        $this->bindCard($other);
        $late = $this->book($other, $this->psy, '2026-10-06 15:00');
        $this->assertSame([1440], $late->reminders_sent);
        $this->artisan('book:send-reminders');
        $this->assertSame(0, UserNotification::where('user_id', $other->id)->where('template_code', 'book.session_reminder')->count());
        $this->moveTo('2026-10-06 14:00');
        $this->artisan('book:send-reminders');
        $this->assertSame(1, UserNotification::where('user_id', $other->id)->where('template_code', 'book.session_reminder')->count());
    }
}
