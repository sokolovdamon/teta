<?php

namespace App\Modules\Booking\Support;

use Illuminate\Http\Exceptions\HttpResponseException;

/** A business-rule refusal with a machine-readable code for the frontend (card_required, payment_declined, …). */
final class BookingError
{
    public static function fail(string $message, string $code, string $field = 'booking', int $status = 422, array $extra = []): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => [$field => [$message]],
            ...$extra,
        ], $status));
    }
}
