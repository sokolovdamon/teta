<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Models\GiftCertificate;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\GiftCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** SITE-20 (purchase by anyone) and CL-07 (activation by a client), DEC-43. */
class GiftCertificateController extends Controller
{
    public function __construct(private GiftCertificateService $certificates, private BalanceService $balance) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->certificates->options()]);
    }

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nominal' => ['required', 'integer'],
            'buyer_email' => ['required', 'email', 'max:255'],
            'buyer_name' => ['nullable', 'string', 'max:100'],
            'recipient_name' => ['nullable', 'string', 'max:100'],
            'recipient_email' => ['nullable', 'required_if:send_to,recipient', 'email', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
            'send_to' => ['nullable', Rule::in(['recipient', 'buyer'])],
            'accept_offer' => ['accepted'],
            'accept_personal_data' => ['accepted'],
        ], [
            'accept_offer.accepted' => 'Примите условия оферты.',
            'accept_personal_data.accepted' => 'Дайте согласие на обработку персональных данных.',
        ]);
        $payment = $this->certificates->purchase($request->user('sanctum'), $data);

        return response()->json([
            'payment_id' => $payment->id,
            'certificate_id' => $payment->meta('certificate_id'),
            'confirmation_url' => $payment->confirmation_url,
        ], 201);
    }

    /** Public status for the success page (no code, no personal data). */
    public function status(GiftCertificate $certificate): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $certificate->id,
            'status' => $certificate->status,
            'nominal' => (int) $certificate->nominal,
            'send_to' => $certificate->send_to,
            'valid_until' => $certificate->valid_until?->toIso8601String(),
        ]]);
    }

    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        $c = $this->certificates->activate($request->user(), $data['code']);

        return response()->json([
            'data' => GiftCertificateService::toApi($c),
            'balance' => $this->balance->summary($request->user()->id),
        ]);
    }
}
