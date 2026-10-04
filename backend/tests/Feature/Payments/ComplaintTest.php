<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Services\ComplaintService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Booking\BookingFixtures;
use Tests\TestCase;

/** ST-05 / SEQ-06 / DEC-23: complaints about a charge, 14 working days by the production calendar. */
class ComplaintTest extends TestCase
{
    use BookingFixtures;

    private User $clientUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock('2026-10-26 07:00:00'); // Monday
        $this->clientUser = $this->client();
        $this->bindCard($this->clientUser);
    }

    /** A late-cancelled session with 4 000 ₽ retained. */
    private function retainedSession(): TherapySession
    {
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $session = $this->book($this->clientUser, $p, '2026-10-26 15:00');
        $this->actAs($this->clientUser);
        $this->postJson("/api/v1/booking/sessions/{$session->id}/cancel", ['confirm_late' => true])->assertOk();

        return $session->fresh();
    }

    public function test_due_date_counts_working_days_skipping_holidays(): void
    {
        // 14 working days from Monday 26.10.2026; 04.11 is a public holiday.
        $this->assertSame('2026-11-16', ComplaintService::dueDate(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/Moscow'))->toDateString());
        // Without the holiday it would have been 13.11.
        DB::table('calendar_days')->where('date', '2026-11-04')->delete();
        $this->assertSame('2026-11-13', ComplaintService::dueDate(CarbonImmutable::parse('2026-10-26 10:00', 'Europe/Moscow'))->toDateString());
    }

    public function test_only_charged_sessions_accept_complaints(): void
    {
        $p = $this->makePsychologist();
        $booked = $this->book($this->clientUser, $p, '2026-10-30 15:00');
        $this->actAs($this->clientUser);
        $this->postJson('/api/v1/payments/complaints', ['session_id' => $booked->id, 'reason' => 'Мне не понравилось списание'])
            ->assertStatus(422)->assertJsonPath('code', 'not_charged');
        $foreign = $this->retainedSession();
        $this->actAs($this->client());
        $this->postJson('/api/v1/payments/complaints', ['session_id' => $foreign->id, 'reason' => 'Чужая сессия для проверки'])->assertNotFound();
    }

    public function test_full_cycle_with_question_and_partial_refund(): void
    {
        $admin = $this->userWithRole('admin');
        $session = $this->retainedSession();
        $res = $this->postJson('/api/v1/payments/complaints', ['session_id' => $session->id, 'reason' => 'Отменил из-за болезни, прошу вернуть часть'])
            ->assertCreated()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.due_date', '2026-11-16')
            ->assertJsonPath('data.working_days_left', 14);
        $id = $res->json('data.id');
        $this->assertCount(1, $this->events('pay.complaint.opened'));
        $this->assertTrue(UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'pay.complaint_received')->exists());
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('template_code', 'pay.complaint_new_admin')->exists());

        // A second complaint about the same session is added to the open one.
        $this->postJson('/api/v1/payments/complaints', ['session_id' => $session->id, 'reason' => 'Дополнение: есть справка'])->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertSame(1, ChargeComplaint::count());

        $this->actAs($admin);
        $this->getJson('/api/v1/admin/finance/complaints')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.client.email', $this->clientUser->email);
        $this->postJson("/api/v1/admin/finance/complaints/{$id}/take")->assertOk()->assertJsonPath('data.status', 'in_review');
        $this->postJson("/api/v1/admin/finance/complaints/{$id}/ask", ['question' => 'Пришлите, пожалуйста, справку'])->assertOk()->assertJsonPath('data.status', 'waiting_client');

        $this->actAs($this->clientUser);
        $this->postJson("/api/v1/payments/complaints/{$id}/answer", ['text' => 'Справка приложена в поддержку'])->assertOk()->assertJsonPath('data.status', 'in_review');

        $this->actAs($admin);
        $this->postJson("/api/v1/admin/finance/complaints/{$id}/approve", ['amount' => 500000, 'comment' => 'Слишком много'])->assertStatus(422);
        $this->postJson("/api/v1/admin/finance/complaints/{$id}/approve", ['amount' => 100000, 'comment' => 'Возвращаем четверть'])
            ->assertOk()->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.refund_amount', 100000);

        $this->assertSame(100000, $this->balanceOf($this->clientUser)['available']);
        $event = $this->events('pay.complaint.refunded')->first();
        $this->assertSame($session->id, $event->payload['session_id']);
        $this->assertSame(100000, $event->payload['refund_amount']);
        $this->assertEquals(25.0, $event->payload['share_percent']);
        $this->assertSame(300000, $session->fresh()->retainedAmount());
        $this->assertTrue(AuditLog::where('action', 'complaint.approved')->exists());
        $this->assertTrue(UserNotification::where('user_id', $this->clientUser->id)->where('template_code', 'pay.complaint_refunded')->exists());
    }

    public function test_reject_and_withdraw(): void
    {
        $admin = $this->userWithRole('admin');
        $session = $this->retainedSession();
        $c = app(ComplaintService::class)->create($this->clientUser, $session, 'Не согласен со списанием');
        $this->actAs($this->clientUser);
        $this->postJson("/api/v1/payments/complaints/{$c->id}/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $c2 = app(ComplaintService::class)->create($this->clientUser, $session, 'Передумал, всё же не согласен');
        $this->actAs($admin);
        $this->postJson("/api/v1/admin/finance/complaints/{$c2->id}/reject", ['comment' => 'Нет'])->assertStatus(409);
        $this->postJson("/api/v1/admin/finance/complaints/{$c2->id}/take")->assertOk();
        $this->postJson("/api/v1/admin/finance/complaints/{$c2->id}/reject", ['comment' => 'Сессия отменена позже срока, основания для возврата нет'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame(0, $this->balanceOf($this->clientUser)['available']);
        $this->actAs($this->clientUser);
        $this->postJson("/api/v1/payments/complaints/{$c2->id}/withdraw")->assertStatus(409);
    }

    public function test_sla_control_highlights_soon_and_alerts_super_admin_when_overdue(): void
    {
        $super = $this->userWithRole('super_admin');
        $admin = $this->userWithRole('admin');
        $session = $this->retainedSession();
        $c = app(ComplaintService::class)->create($this->clientUser, $session, 'Не согласен со списанием');

        $this->moveTo('2026-11-11 10:00'); // 3 working days left: 12, 13, 16
        $this->artisan('pay:complaints-sla')->assertSuccessful();
        $this->assertNull($c->fresh()->sla_level);

        $this->moveTo('2026-11-12 10:00');
        $this->artisan('pay:complaints-sla');
        $this->assertSame('soon', $c->fresh()->sla_level);
        $this->assertTrue(UserNotification::where('user_id', $admin->id)->where('template_code', 'pay.complaint_due_soon_admin')->exists());

        $this->moveTo('2026-11-17 10:00');
        $this->artisan('pay:complaints-sla');
        $this->artisan('pay:complaints-sla');
        $this->assertSame('overdue', $c->fresh()->sla_level);
        $this->assertSame(1, UserNotification::where('user_id', $super->id)->where('template_code', 'pay.complaint_overdue_admin')->count());
        $this->actAs($admin);
        $this->getJson('/api/v1/admin/finance/complaints')->assertOk()->assertJsonPath('data.0.sla', 'overdue');
    }
}
