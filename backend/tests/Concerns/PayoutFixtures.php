<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Corporate\Models\Company;
use App\Modules\Corporate\Models\CorporateParticipation;
use App\Modules\Corporate\Models\CorporateProgram;
use App\Modules\Payments\Gateway\GatewayOperation;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Gateway\WebhookEvent;
use App\Modules\Payments\Gateway\WebhookHandlers;
use App\Modules\Payments\Models\ChargeComplaint;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Services\ActivityService;
use Carbon\CarbonImmutable;
use Tests\Support\FakePaymentGateway;

/**
 * PAYOUT / PROMO fixtures. Sessions reach their outcome the way BOOK does it: transitionTo() records
 * book.session.{status} in the outbox and the PAYOUT listeners react after commit. Use with CreatesPsychologists.
 */
trait PayoutFixtures
{
    protected FakePaymentGateway $gateway;

    protected function fakeGateway(): FakePaymentGateway
    {
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);

        return $this->gateway;
    }

    protected function payoutCard(User $user, string $token = 'tok_payout_0001', string $mask = '2200 00** **** 0001'): PaymentMethod
    {
        return PaymentMethod::create([
            'user_id' => $user->id, 'gateway' => 'fake', 'token' => $token, 'purpose' => 'payout', 'card_mask' => $mask,
            'card_brand' => 'MIR', 'exp_month' => 12, 'exp_year' => 2030, 'is_default' => true, 'status' => 'active',
        ]);
    }

    /** The monthly supervision requirement is met for the current month (Moscow time). */
    protected function supervisionMet(Psychologist $p): void
    {
        app(ActivityService::class)->markMonthMet($p->fresh());
    }

    /** A paid session that BOOK moves to "held". */
    protected function holdSession(Psychologist $p, ?User $client = null, array $attrs = []): TherapySession
    {
        $session = $this->paidSession($p, $client, TherapySession::IN_PROGRESS, $attrs);
        $session->transitionTo(TherapySession::HELD, null, 'session held', ['outcome_at' => now()], ['price' => (int) $session->price]);

        return $session->fresh();
    }

    protected function paidSession(Psychologist $p, ?User $client = null, string $status = TherapySession::PAID, array $attrs = []): TherapySession
    {
        $client ??= $this->userWithRole('client');
        $start = CarbonImmutable::now()->subHour();

        return $this->makeSession($p, $client, $start, $status, ['paid_at' => $start->subHours(12), 'payment_source' => 'card', ...$attrs]);
    }

    /** PAY approved a complaint and refunded the client (pay.complaint.refunded). */
    protected function refundComplaint(TherapySession $session, float|int $share, ?int $refund = null): ChargeComplaint
    {
        $complaint = new ChargeComplaint([
            'therapy_session_id' => $session->id,
            'client_id' => $session->client_id,
            'reason' => 'Сессия прошла не так, как ожидалось',
            'due_date' => now()->addDays(20)->toDateString(),
            'refund_amount' => $refund,
        ]);
        $complaint->forceFill(['status' => 'approved'])->save();
        $complaint->transitionTo('refunded', null, 'complaint approved', [], [
            'session_id' => $session->id,
            'refund_amount' => $refund ?? (int) round(((int) $session->amount_due) * $share / 100),
            'share_percent' => $share,
        ]);

        return $complaint;
    }

    protected function corporateParticipation(User $client): CorporateParticipation
    {
        $company = Company::create(['name' => 'ООО «Ромашка»']);
        $program = CorporateProgram::create([
            'company_id' => $company->id, 'title' => 'Забота о сотрудниках', 'code' => 'ROMASHKA'.random_int(100, 999),
            'sessions_limit' => 5, 'company_session_price' => 250000,
        ]);
        $participation = new CorporateParticipation(['corporate_program_id' => $program->id, 'user_id' => $client->id, 'work_email' => 'staff'.random_int(1000, 9999).'@romashka.ru']);
        $participation->forceFill(['status' => 'active'])->save();

        return $participation;
    }

    protected function payoutWebhook(Payout $payout, string $type, ?string $errorCode = null): void
    {
        $status = $type === 'payout.paid' ? GatewayOperation::SUCCEEDED : GatewayOperation::DECLINED;
        WebhookHandlers::dispatch(new WebhookEvent(
            'evt_'.uniqid(),
            $type,
            $payout->idempotency_key,
            new GatewayOperation($status, FakePaymentGateway::gatewayId($payout->idempotency_key), errorCode: $errorCode),
        ));
    }

    /** Monday of the week after $date, 03:00 MSK — when the scheduler builds the registry. */
    protected function nextMonday(string $date = 'now'): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Europe/Moscow')->startOfDay()->next(CarbonImmutable::MONDAY)->setTime(3, 0);
    }
}
