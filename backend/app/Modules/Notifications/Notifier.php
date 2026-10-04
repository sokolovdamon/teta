<?php

namespace App\Modules\Notifications;

use App\Models\User;
use App\Modules\Notifications\Events\UserNotificationCreated;
use App\Modules\Notifications\Jobs\SendMailMessage;
use App\Modules\Notifications\Mail\TemplatedMail;
use App\Modules\Notifications\Models\MailMessage;
use App\Modules\Notifications\Models\NotificationPreference;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NOTIF (DEC-31): email + notification centre in the cabinet. No Telegram, SMS or web push.
 *
 *   app(Notifier::class)->send($user, 'book.session_booked', ['date' => ...], link: '/client/sessions');
 *
 * Templates come from the DB (ADM-10) or, if not yet seeded, from module catalogues.
 */
class Notifier
{
    /** @param  array<string, mixed>  $vars */
    public function send(User $user, string $code, array $vars = [], ?string $link = null, ?string $actionText = null): ?UserNotification
    {
        $template = $this->template($code);
        if (! $template || ! ($template['is_active'] ?? true)) {
            Log::warning("Notification template {$code} is missing or inactive");

            return null;
        }
        $vars = ['name' => $user->name, 'app_url' => config('app.frontend_url'), ...$vars];
        $link ??= $template['link'] ?? null;
        $link = $link ? Renderer::render($link, $vars) : null;

        $notification = null;
        if ($template['send_center'] ?? true) {
            $notification = UserNotification::create([
                'user_id' => $user->id,
                'template_code' => $code,
                'title' => Renderer::render($template['center_text'] ?? $template['subject'], $vars),
                'body' => null,
                'link' => $link,
                'data' => ['template' => $code],
            ]);
            DB::afterCommit(function () use ($notification) {
                try {
                    broadcast(new UserNotificationCreated($notification));
                } catch (Throwable $e) {
                    Log::info('Broadcast skipped: '.$e->getMessage());
                }
            });
        }

        $canEmail = ($template['send_email'] ?? true)
            && ! in_array($user->status, [User::STATUS_DELETED], true)
            && (($template['is_transactional'] ?? true) || $this->allowsMarketing($user));
        if ($canEmail && ! $this->remindersOff($user, $code)) {
            $this->queueMail($user->email, $template, $vars, $link, $actionText, $user->id, $code);
        }

        return $notification;
    }

    /** Letter to an address without an account (e.g. certificate recipient, pair partner invitation). */
    public function sendToEmail(string $email, string $code, array $vars = [], ?string $link = null, ?string $actionText = null): void
    {
        $template = $this->template($code);
        if (! $template || ! ($template['is_active'] ?? true)) {
            return;
        }
        $vars = ['app_url' => config('app.frontend_url'), ...$vars];
        $this->queueMail($email, $template, $vars, $link ? Renderer::render($link, $vars) : null, $actionText, null, $code);
    }

    /** @return array<string, mixed>|null */
    public function template(string $code): ?array
    {
        $db = NotificationTemplate::where('code', $code)->first();
        if ($db) {
            return $db->toArray();
        }

        return NotificationCatalog::find($code);
    }

    /** @param  array<string, mixed>  $template */
    private function queueMail(string $to, array $template, array $vars, ?string $link, ?string $actionText, ?string $userId, string $code): void
    {
        $subject = Renderer::render($template['subject'], $vars);
        $html = Renderer::render(nl2br($template['body']), $vars, escape: true);
        $url = $link ? (str_starts_with($link, 'http') ? $link : rtrim((string) config('app.frontend_url'), '/').$link) : null;

        $message = MailMessage::create([
            'user_id' => $userId,
            'to' => $to,
            'subject' => $subject,
            'template_code' => $code,
            'stream' => ($template['is_transactional'] ?? true) ? 'transactional' : 'marketing',
            'status' => 'queued',
        ]);

        $mail = new TemplatedMail($subject, $html, $url, $actionText ?? ($template['action_text'] ?? ($url ? 'Открыть' : null)));
        DB::afterCommit(fn () => SendMailMessage::dispatch($message->id, $mail));
    }

    private function allowsMarketing(User $user): bool
    {
        return (bool) NotificationPreference::find($user->id)?->marketing_emails;
    }

    private function remindersOff(User $user, string $code): bool
    {
        if (! str_contains($code, 'reminder')) {
            return false;
        }

        return NotificationPreference::find($user->id)?->session_reminders === false;
    }
}
