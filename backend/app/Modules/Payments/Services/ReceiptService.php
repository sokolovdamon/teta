<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Gateway\ReceiptData;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Models\Receipt;

/**
 * 54-ФЗ receipts (DEC-22): PREPAYMENT_FULL when a B2C session is paid, a separate receipt for a gift certificate
 * sale (DEC-43), "возврат прихода" for refunds to a card. The receipt is registered by the gateway together with
 * the operation; the fiscal data come back in the response or the webhook.
 */
class ReceiptService
{
    public static function forSession(int $amount, string $title, ?string $email): ReceiptData
    {
        return new ReceiptData(
            (string) config('payments.receipts.session_method', 'PREPAYMENT_FULL'),
            [['name' => $title, 'amount' => $amount, 'quantity' => 1, 'vat' => (string) config('payments.receipts.vat', 'none')]],
            $email,
        );
    }

    public static function forCertificate(int $amount, ?string $email): ReceiptData
    {
        return new ReceiptData(
            (string) config('payments.receipts.certificate_method', 'ADVANCE'),
            [['name' => 'Подарочный сертификат на психологические консультации', 'amount' => $amount, 'quantity' => 1, 'vat' => (string) config('payments.receipts.vat', 'none')]],
            $email,
        );
    }

    /** @return array<string, mixed> */
    public static function toArray(ReceiptData $receipt): array
    {
        return ['calculation_method' => $receipt->calculationMethod, 'items' => $receipt->items, 'customer_email' => $receipt->customerEmail];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(?array $data): ?ReceiptData
    {
        if (! $data || empty($data['calculation_method'])) {
            return null;
        }

        return new ReceiptData((string) $data['calculation_method'], $data['items'] ?? [], $data['customer_email'] ?? null);
    }

    /** @param  array<string, mixed>|null  $fiscal */
    public function income(Payment $payment, ?array $fiscal): ?Receipt
    {
        $data = $payment->meta('receipt');
        if (! $data || Receipt::where('payment_id', $payment->id)->where('kind', 'income')->exists()) {
            return null;
        }

        return Receipt::create([
            'payment_id' => $payment->id,
            'kind' => 'income',
            'calculation_method' => $data['calculation_method'],
            'amount' => $payment->amount,
            'items' => $data['items'] ?? [],
            'customer_email' => $data['customer_email'] ?? null,
            'status' => $fiscal ? 'registered' : 'pending',
            'fiscal_data' => $fiscal,
        ]);
    }

    /** @param  array<string, mixed>|null  $fiscal */
    public function refund(PaymentRefund $refund, ?array $fiscal): ?Receipt
    {
        if (Receipt::where('payment_refund_id', $refund->id)->exists()) {
            return null;
        }
        $payment = $refund->payment;
        $data = $payment->meta('receipt') ?? [];

        return Receipt::create([
            'payment_id' => $payment->id,
            'payment_refund_id' => $refund->id,
            'kind' => 'income_return',
            'calculation_method' => $data['calculation_method'] ?? (string) config('payments.receipts.session_method'),
            'amount' => $refund->amount,
            'items' => [['name' => 'Возврат: '.($data['items'][0]['name'] ?? $payment->description), 'amount' => $refund->amount, 'quantity' => 1]],
            'customer_email' => $data['customer_email'] ?? null,
            'status' => $fiscal ? 'registered' : 'pending',
            'fiscal_data' => $fiscal,
        ]);
    }

    public static function toApi(Receipt $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'kind_label' => $r->kind === 'income' ? 'Чек прихода' : 'Чек возврата прихода',
            'calculation_method' => $r->calculation_method,
            'amount' => $r->amount,
            'status' => $r->status,
            'fiscal' => $r->fiscal_data ? array_intersect_key($r->fiscal_data, array_flip(['fn', 'fd', 'fpd', 'registered_at'])) : null,
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
