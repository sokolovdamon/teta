<?php

namespace App\Modules\Payments\Gateway\Emulator;

use App\Support\Database\UtcDates;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An operation on the emulator side (the "provider"). Keyed by the platform's idempotency key:
 * a repeated key always returns the same operation, so a retry can never charge twice.
 *
 * kind: binding | charge | payment | refund | payout
 * status: requires_action | awaiting_3ds | succeeded | declined | pending
 */
class EmulatorOperation extends Model
{
    use HasUuids, UtcDates;

    protected $table = 'emulator_operations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'save_card' => 'boolean',
            'webhook_suppressed' => 'boolean',
            'receipt' => 'array',
            'fiscal' => 'array',
            'webhook_sent_at' => 'datetime',
        ];
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['succeeded', 'declined'], true);
    }

    /** Checkout page of the emulator used by the frontend (/pay/emulator/{id}). */
    public function checkoutUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/pay/emulator/'.$this->id;
    }

    /** @return array<string, mixed>|null */
    public function card(): ?array
    {
        if (! $this->token || ! in_array($this->kind, ['binding', 'payment'], true) || $this->status !== 'succeeded') {
            return null;
        }

        return [
            'token' => $this->token,
            'mask' => $this->card_mask,
            'brand' => $this->card_brand,
            'exp_month' => $this->exp_month,
            'exp_year' => $this->exp_year,
        ];
    }
}
