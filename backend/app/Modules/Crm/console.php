<?php

use App\Modules\Crm\Services\NoteRetention;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// P-NOTES-RETENTION: private notes are destroyed when the retention period after the end of work has passed.
Artisan::command('crm:purge-notes', function (NoteRetention $retention) {
    $stats = $retention->purgeExpired();
    $this->info("Pairs: {$stats['pairs']}, notes destroyed: {$stats['notes']}");
})->purpose('Destroy private notes whose retention period has expired');

Schedule::command('crm:purge-notes')->dailyAt('03:40')->timezone('Europe/Moscow')->withoutOverlapping();
