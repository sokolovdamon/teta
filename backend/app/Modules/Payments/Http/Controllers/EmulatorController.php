<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Gateway\Emulator\EmulatorCards;
use App\Modules\Payments\Gateway\Emulator\EmulatorCheckout;
use App\Modules\Payments\Gateway\Emulator\EmulatorOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Hosted checkout of the payment emulator used by /pay/emulator/{id} (DEC-38). Available only while the
 * emulator is the configured gateway.
 */
class EmulatorController extends Controller
{
    public function __construct(private EmulatorCheckout $checkout)
    {
        abort_unless(config('payments.gateway') === 'emulator', 404);
    }

    public function testCards(): JsonResponse
    {
        return response()->json(['data' => EmulatorCards::catalog(), 'timeout_rule' => 'Сумма, оканчивающаяся на 13 копеек, имитирует обрыв связи.']);
    }

    public function show(EmulatorOperation $operation): JsonResponse
    {
        abort_unless(in_array($operation->kind, ['binding', 'payment'], true), 404);

        return response()->json(['data' => $this->checkout->show($operation)]);
    }

    public function card(Request $request, EmulatorOperation $operation): JsonResponse
    {
        abort_unless(in_array($operation->kind, ['binding', 'payment'], true), 404);
        $data = $request->validate([
            'card_number' => ['required', 'string', 'max:32'],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year' => ['required', 'integer', 'between:0,2100'],
            'cvc' => ['required', 'digits_between:3,4'],
        ]);
        $op = $this->checkout->submitCard($operation, $data);

        return response()->json(['data' => $this->checkout->show($op)]);
    }

    public function threeDs(Request $request, EmulatorOperation $operation): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['confirm', 'decline'])]]);
        $op = $this->checkout->confirm3ds($operation, $data['decision'] === 'confirm');

        return response()->json(['data' => $this->checkout->show($op)]);
    }

    public function cancel(EmulatorOperation $operation): JsonResponse
    {
        $op = $this->checkout->cancel($operation);

        return response()->json(['data' => $this->checkout->show($op)]);
    }
}
