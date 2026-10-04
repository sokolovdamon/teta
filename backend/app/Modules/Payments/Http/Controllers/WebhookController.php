<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Services\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/payments/webhooks/{provider}: signature check, inbox, routing to the owning module (BR-PAY-06). */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGateway $gateway, WebhookProcessor $processor): JsonResponse
    {
        abort_unless($provider === $gateway->name(), 404);
        [$status, $body] = $processor->receive($gateway, $request);

        return response()->json($body, $status);
    }
}
