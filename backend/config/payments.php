<?php

/*
 * PAY (DEC-38, Q-43): the payment service is not chosen yet, so every money operation goes through the
 * PaymentGateway contract. The only driver today is the test emulator; a real adapter is added later
 * without changes in BOOK, SUPERV, PAYOUT, PROMO or B2B.
 */
return [
    // emulator (the only driver until Q-43 is answered)
    'gateway' => env('PAYMENT_GATEWAY', 'emulator'),

    'emulator' => [
        // HMAC-SHA256 secret used to sign emulator webhooks (header X-Emulator-Signature).
        'secret' => env('PAYMENT_EMULATOR_SECRET', 'teta-emulator-dev-secret'),
        // How the emulator delivers webhooks to our own endpoint:
        //  sync  — right after the operation's transaction commits, in-process, through the same verification path;
        //  queue — job on the "webhooks" queue (needs a queue worker);
        //  http  — job that POSTs to webhook_url (closest to a real provider).
        'webhook_delivery' => env('PAYMENT_EMULATOR_WEBHOOKS', 'sync'),
        'webhook_url' => env('PAYMENT_EMULATOR_WEBHOOK_URL'),
    ],

    // 54-ФЗ receipts (DEC-22). Agent attributes and the seller (ИП Иващенко, DEC-50) are added by the real adapter.
    'receipts' => [
        'session_method' => 'PREPAYMENT_FULL',
        // Gift certificate sale is an advance for future services; the accountant confirms the order of receipts (DEC-43).
        'certificate_method' => env('PAYMENT_CERTIFICATE_RECEIPT_METHOD', 'ADVANCE'),
        'vat' => 'none',
    ],

    // Technical timings of the charge pipeline (not business rules, so not P-* parameters).
    'stuck_after_minutes' => 10,
    'unknown_recheck_minutes' => 10,
    // A payer checkout that was opened but not finished stops blocking automatic retries after this time.
    'payer_checkout_ttl_minutes' => 30,
];
