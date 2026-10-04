<?php

namespace App\Modules\Psychologists\Services;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BR-PSY-06: work status independent of supervision. The psychologist pauses and resumes taking NEW bookings
 * (current clients' sessions stay); an administrator blocks and unblocks. Both hide the profile from the catalog
 * and matching (Psychologist::scopeBookable). BOOK reacts to psy.work_status.blocked.
 */
class WorkStatusService
{
    public function __construct(private Notifier $notifier) {}

    public function pause(Psychologist $p, User $actor, ?string $reason = null): void
    {
        if ($p->work_status !== 'active') {
            throw ValidationException::withMessages(['work_status' => $p->work_status === 'blocked'
                ? 'Профиль заблокирован администратором.'
                : 'Приём новых записей уже приостановлен.']);
        }
        $p->transitionTo('paused', $actor->id, $reason, ['work_status_reason' => $reason], field: 'work_status', event: 'psy.work_status.paused');
    }

    public function resume(Psychologist $p, User $actor): void
    {
        if ($p->work_status !== 'paused') {
            throw ValidationException::withMessages(['work_status' => $p->work_status === 'blocked'
                ? 'Профиль заблокирован администратором: снять блокировку может только администратор.'
                : 'Приём новых записей уже открыт.']);
        }
        $p->transitionTo('active', $actor->id, 'resumed', ['work_status_reason' => null], field: 'work_status', event: 'psy.work_status.resumed');
    }

    public function block(Psychologist $p, User $admin, string $reason): void
    {
        if ($p->work_status === 'blocked') {
            throw ValidationException::withMessages(['work_status' => 'Психолог уже заблокирован.']);
        }
        DB::transaction(function () use ($p, $admin, $reason) {
            $p->transitionTo('blocked', $admin->id, $reason, ['work_status_reason' => $reason], ['previous' => $p->work_status], field: 'work_status', event: 'psy.work_status.blocked');
            Audit::log('ADM-03', 'psychologist.blocked', $p, null, $reason, $admin->id);
            $this->notifier->send($p->user, 'psy.work_status_blocked', ['reason' => $reason], '/pro/profile');
        });
    }

    public function unblock(Psychologist $p, User $admin, ?string $comment = null): void
    {
        if ($p->work_status !== 'blocked') {
            throw ValidationException::withMessages(['work_status' => 'Психолог не заблокирован.']);
        }
        DB::transaction(function () use ($p, $admin, $comment) {
            $p->transitionTo('active', $admin->id, $comment ?? 'unblocked', ['work_status_reason' => null], field: 'work_status', event: 'psy.work_status.unblocked');
            Audit::log('ADM-03', 'psychologist.unblocked', $p, null, $comment, $admin->id);
            $this->notifier->send($p->user, 'psy.work_status_unblocked', [], '/pro/profile');
        });
    }
}
