<?php

namespace App\Modules\Account\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Notifications\Notifier;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\RbacService;
use App\Support\Events\Outbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ADM-02: search, filters, user card, blocking, role change, password reset. */
class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $users = $this->query($request)->paginate(min((int) $request->integer('per_page', 30), 100));

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $u) => $this->row($u)),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'per_page' => $users->perPage(), 'total' => $users->total()],
        ]);
    }

    public function show(User $user)
    {
        $user->load('roles', 'psychologist');

        return response()->json([
            'data' => (new UserResource($user))->resolve(),
            'stats' => [
                'sessions_total' => $user->hasRole('client') ? DB::table('therapy_sessions')->where('client_id', $user->id)->count() : null,
                'sessions_held' => $user->hasRole('client') ? DB::table('therapy_sessions')->where('client_id', $user->id)->where('status', 'held')->count() : null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'blocked_reason' => $user->blocked_reason,
                'blocked_at' => $user->blocked_at?->toIso8601String(),
            ],
        ]);
    }

    public function block(Request $request, User $user, Notifier $notifier)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_if($user->id === $request->user()->id, 422, 'Нельзя заблокировать самого себя.');
        abort_if($user->isSuperAdmin() && ! $request->user()->isSuperAdmin(), 403);

        $user->forceFill(['status' => User::STATUS_BLOCKED, 'blocked_reason' => $data['reason'], 'blocked_at' => now()])->save();
        $user->tokens()->delete();
        // BOOK/PSY react to this event: upcoming sessions are cancelled by the platform with a full refund (BR-CANC-09).
        Outbox::record('account.user.blocked', $user, ['reason' => $data['reason']], $request->user()->id);
        Audit::log('ADM-02', 'user.blocked', $user, null, $data['reason']);
        $notifier->send($user, 'auth.account_blocked', ['reason' => $data['reason']]);

        return response()->json(['data' => $this->row($user->fresh('roles'))]);
    }

    public function unblock(Request $request, User $user, Notifier $notifier)
    {
        abort_unless($user->status === User::STATUS_BLOCKED, 422, 'Пользователь не заблокирован.');
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'blocked_reason' => null, 'blocked_at' => null])->save();
        Outbox::record('account.user.unblocked', $user, [], $request->user()->id);
        Audit::log('ADM-02', 'user.unblocked', $user);
        $notifier->send($user, 'auth.account_unblocked');

        return response()->json(['data' => $this->row($user->fresh('roles'))]);
    }

    public function setRoles(Request $request, User $user, RbacService $rbac)
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::exists('roles', 'code')]]);
        $actor = $request->user();
        $grantsAdmin = array_intersect($data['roles'], [Role::SUPER_ADMIN]) !== [];
        abort_if($grantsAdmin && ! $actor->isSuperAdmin(), 403, 'Роль супер-администратора назначает только супер-администратор.');
        abort_if($user->isSuperAdmin() && ! $actor->isSuperAdmin(), 403);
        $rbac->setUserRoles($user, $data['roles'], $actor);

        return response()->json(['data' => $this->row($user->fresh('roles'))]);
    }

    public function resetPassword(User $user, Notifier $notifier)
    {
        $token = Password::broker()->createToken($user);
        $user->tokens()->delete();
        $url = rtrim((string) config('app.frontend_url'), '/').'/auth/reset?'.http_build_query(['token' => $token, 'email' => $user->email]);
        $notifier->send($user, 'auth.password_reset_by_admin', [], $url, 'Задать пароль');
        Audit::log('ADM-02', 'user.password_reset', $user);

        return response()->json(['ok' => true]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request);
        Audit::log('ADM-02', 'users.exported', null, $request->query());

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Email', 'Имя', 'Фамилия', 'Роли', 'Статус', 'Email подтверждён', 'Регистрация', 'Последний вход'], ';');
            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $u) {
                    fputcsv($out, [$u->id, $u->email, $u->name, $u->last_name, implode(',', $u->roleCodes()), $u->status, $u->email_verified_at ? 'да' : 'нет', $u->created_at?->toDateString(), $u->last_login_at?->toDateTimeString()], ';');
                }
            });
            fclose($out);
        }, 'users.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request)
    {
        return User::query()->with('roles')
            ->when($request->query('q'), function ($q, $v) {
                $q->where(fn ($w) => $w->where('email', 'ilike', "%{$v}%")->orWhere('name', 'ilike', "%{$v}%")->orWhere('last_name', 'ilike', "%{$v}%")->orWhere('phone', 'ilike', "%{$v}%"));
            })
            ->when($request->query('role'), fn ($q, $v) => $q->whereHas('roles', fn ($r) => $r->where('code', $v)))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('verified') !== null, fn ($q) => $request->boolean('verified') ? $q->whereNotNull('email_verified_at') : $q->whereNull('email_verified_at'))
            ->when($request->query('registered_from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('registered_to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('created_at');
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'last_name' => $u->last_name, 'phone' => $u->phone,
            'roles' => $u->roleCodes(), 'status' => $u->status, 'email_verified' => $u->email_verified_at !== null,
            'created_at' => $u->created_at?->toIso8601String(), 'last_login_at' => $u->last_login_at?->toIso8601String(),
        ];
    }
}
