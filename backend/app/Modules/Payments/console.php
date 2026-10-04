<?php

use App\Modules\Payments\Models\ClientBalanceOperation;
use App\Modules\Payments\Models\PaymentRefund;
use App\Modules\Payments\Services\BalanceService;
use App\Modules\Payments\Services\ChargeService;
use App\Modules\Payments\Services\ComplaintService;
use App\Modules\Payments\Services\GiftCertificateService;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\WithdrawalService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// ST-02 / SEQ-02: deadlines first, then due charges and retries (row locks, one attempt per task at a time).
Artisan::command('pay:dispatch-due-charges', function (ChargeService $charges) {
    $stats = $charges->dispatchDue();
    $this->info("Attempted: {$stats['attempted']}, failed final: {$stats['failed_final']}");
})->purpose('Run due autocharges, retries and charge deadlines');

// BR-PAY-13: attempts in progress for more than 10 minutes, payer payments and refunds with a lost result.
Artisan::command('pay:recover-stuck-charges', function (ChargeService $charges, PaymentService $payments) {
    $count = $charges->recoverStuck();
    foreach (PaymentRefund::where('status', 'pending')->where('updated_at', '<=', now()->subMinutes(10))->limit(200)->get() as $refund) {
        $payments->resolveRefund($refund);
        $count++;
    }
    $this->info("Checked: {$count}");
})->purpose('Query the gateway for operations with an unknown result');

// ST-04: withdrawals whose processing job did not run.
Artisan::command('pay:process-withdrawals', function (WithdrawalService $withdrawals) {
    $ids = ClientBalanceOperation::where('status', 'withdraw_reserved')->where('created_at', '<=', now()->subMinutes(2))->pluck('id');
    foreach ($ids as $id) {
        $withdrawals->process($id);
    }
    $this->info('Processed: '.$ids->count());
})->purpose('Send refunds for reserved withdrawals');

// ST-04 [Рек.]: the materialised balances are reconciled with the operations every day.
Artisan::command('pay:reconcile-balances', function (BalanceService $balance) {
    $fixed = $balance->reconcile();
    $this->info('Fixed balances: '.count($fixed));
})->purpose('Reconcile client balances with balance operations');

// ST-05: complaints with less than 3 working days left and overdue ones (alert to super admins).
Artisan::command('pay:complaints-sla', function (ComplaintService $complaints) {
    $stats = $complaints->checkSla();
    $this->info("Soon: {$stats['soon']}, overdue: {$stats['overdue']}");
})->purpose('Control complaint deadlines');

// ST-18: certificates not activated within P-GIFT-VALIDITY.
Artisan::command('pay:expire-certificates', function (GiftCertificateService $certificates) {
    $this->info('Expired: '.$certificates->expire());
})->purpose('Expire gift certificates');

Schedule::command('pay:dispatch-due-charges')->everyMinute()->withoutOverlapping();
Schedule::command('pay:recover-stuck-charges')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pay:process-withdrawals')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pay:reconcile-balances')->dailyAt('03:10')->timezone('Europe/Moscow')->withoutOverlapping();
Schedule::command('pay:complaints-sla')->dailyAt('09:05')->timezone('Europe/Moscow')->withoutOverlapping();
Schedule::command('pay:expire-certificates')->dailyAt('00:20')->timezone('Europe/Moscow')->withoutOverlapping();
