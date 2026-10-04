<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends one letter by SMTP; after the last retry the process continues without it (BR-NOTIF-09). */
class SendMailMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(public string $mailMessageId, public TemplatedMail $mail)
    {
        $this->onQueue('mail-transactional');
    }

    public function handle(): void
    {
        $message = MailMessage::find($this->mailMessageId);
        if (! $message || $message->status === 'sent') {
            return;
        }
        $message->increment('attempts');
        Mail::to($message->to)->send($this->mail);
        $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        MailMessage::whereKey($this->mailMessageId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
    }
}
