<?php

namespace Tests\Feature\Payments;

use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Receipt;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Booking\BookingFixtures;
use Tests\TestCase;

/** ST-18 with the DM-14 fix, SEQ-18, DEC-43. */
class GiftCertificateTest extends TestCase
{
    use BookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeClock();
    }

    /** Buy a certificate as a guest and pay it in the emulator checkout; returns the code from the letter. */
    private function buy(int $nominal = 500000, string $card = '4111111111111111', string $sendTo = 'recipient'): array
    {
        Mail::fake();
        $res = $this->postJson('/api/v1/payments/gift', [
            'nominal' => $nominal,
            'buyer_email' => 'buyer@example.com',
            'buyer_name' => 'Мария',
            'recipient_name' => 'Анна',
            'recipient_email' => 'anna@example.com',
            'message' => 'С днём рождения!',
            'send_to' => $sendTo,
            'accept_offer' => true,
            'accept_personal_data' => true,
        ])->assertCreated();
        $op = EmulatorOperation::findOrFail(basename($res->json('confirmation_url')));
        app(EmulatorCheckout::class)->submitCard($op, ['card_number' => $card, 'exp_month' => 1, 'exp_year' => 2031, 'cvc' => '123']);
        $code = null;
        Mail::assertSent(TemplatedMail::class, function ($m) use (&$code) {
            if (preg_match('/TETA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $m->bodyHtml, $match) && $m->hasTo('anna@example.com')) {
                $code = $match[0];
            }

            return true;
        });

        return [$res->json('certificate_id'), $code, $res->json('payment_id')];
    }

    public function test_purchase_payment_code_letter_and_separate_receipt(): void
    {
        $this->getJson('/api/v1/payments/gift/options')->assertOk()->assertJsonPath('data.nominals', [300000, 500000, 1000000]);
        $this->postJson('/api/v1/payments/gift', ['nominal' => 123, 'buyer_email' => 'a@b.c', 'accept_offer' => true, 'accept_personal_data' => true])->assertStatus(422);
        $this->postJson('/api/v1/payments/gift', ['nominal' => 500000, 'buyer_email' => 'a@b.c'])->assertStatus(422)->assertJsonValidationErrors(['accept_offer', 'accept_personal_data']);

        [$id, $code, $paymentId] = $this->buy();
        $certificate = GiftCertificate::findOrFail($id);
        $this->assertSame('paid', $certificate->status);
        $this->assertNotNull($code);
        $this->assertSame(hash('sha256', GiftCertificate::normalizeCode($code)), $certificate->code_hash);
        $this->assertNull($certificate->code);
        $this->assertTrue($certificate->valid_until->equalTo(now()->addDays(365)));
        $receipt = Receipt::where('payment_id', $paymentId)->firstOrFail();
        $this->assertSame('ADVANCE', $receipt->calculation_method);
        $this->assertSame('certificate', Payment::findOrFail($paymentId)->purpose);
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('buyer@example.com') && str_contains($m->subjectLine, 'оплачен'));
        $this->getJson("/api/v1/payments/gift/{$id}")->assertOk()->assertJsonPath('data.status', 'paid')->assertJsonMissingPath('data.code');
        $this->getJson("/api/v1/payments/status/{$paymentId}")->assertOk()->assertJsonPath('data.result.certificate_status', 'paid');
    }

    public function test_declined_payment_leaves_certificate_unpaid_without_code(): void
    {
        Mail::fake();
        $res = $this->postJson('/api/v1/payments/gift', ['nominal' => 300000, 'buyer_email' => 'buyer@example.com', 'send_to' => 'buyer', 'accept_offer' => true, 'accept_personal_data' => true])->assertCreated();
        app(EmulatorCheckout::class)->cancel(EmulatorOperation::findOrFail(basename($res->json('confirmation_url'))));
        $c = GiftCertificate::firstOrFail();
        $this->assertSame('unpaid', $c->status);
        $this->assertNull($c->code_hash);
    }

    public function test_activation_credits_the_whole_nominal_as_certificate_funds(): void
    {
        [$id, $code] = $this->buy();
        $client = $this->actAs($this->client());
        $this->postJson('/api/v1/payments/certificates/activate', ['code' => 'TETA-0000-0000-0000'])->assertStatus(422)->assertJsonPath('code', 'not_found');
        $this->postJson('/api/v1/payments/certificates/activate', ['code' => strtolower(str_replace('-', ' ', $code))])->assertOk()
            ->assertJsonPath('data.status', 'activated')
            ->assertJsonPath('balance.available', 500000)
            ->assertJsonPath('balance.certificate_available', 500000)
            ->assertJsonPath('balance.withdrawable', 0);
        $this->assertSame($client->id, GiftCertificate::findOrFail($id)->activated_by);
        $this->postJson('/api/v1/payments/certificates/activate', ['code' => $code])->assertStatus(409)->assertJsonPath('code', 'already_activated');
        $this->getJson('/api/v1/payments/summary')->assertOk()->assertJsonPath('data.certificates.0.nominal', 500000);

        // A certificate is a prepayment: the session is paid from it at the charge time, price is not reduced.
        $p = $this->makePsychologist(['price_individual' => 400000]);
        $this->bindCard($client);
        $session = $this->book($client, $p, '2026-10-08 14:00');
        $this->moveTo('2026-10-08 02:00');
        $this->artisan('pay:dispatch-due-charges');
        $session->refresh();
        $this->assertSame('balance', $session->payment_source);
        $this->assertSame(400000, (int) $session->price);
        $this->assertSame(400000, (int) $session->paid_certificate);
        $this->assertSame(100000, $this->balanceOf($client)['certificate_available']);
        // Certificate funds are never withdrawn.
        $this->postJson('/api/v1/payments/balance/withdraw')->assertStatus(422);
    }

    public function test_expiry(): void
    {
        [$id, $code] = $this->buy(300000);
        $this->moveTo('2027-10-06 10:00');
        $this->actAs($this->client());
        $this->postJson('/api/v1/payments/certificates/activate', ['code' => $code])->assertStatus(422)->assertJsonPath('code', 'expired');
        $this->artisan('pay:expire-certificates')->expectsOutput('Expired: 1');
        $this->assertSame('expired', GiftCertificate::findOrFail($id)->status);
    }
}
