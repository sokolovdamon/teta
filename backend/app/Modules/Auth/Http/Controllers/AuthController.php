<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Http\Requests\RegisterRequest;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Notifications\Notifier;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/** X-01 (DEC-29): email + password, email confirmation, password recovery. No codes, 2FA or social login. */
class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request)
    {
        $user = $this->auth->register($request->validated());

        return response()->json(['user' => new UserResource($user->load('roles')), 'token' => $this->auth->issueToken($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = $this->auth->attempt($data['email'], $data['password']);

        return response()->json(['user' => new UserResource($user->load('roles')), 'token' => $this->auth->issueToken($user)]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return new UserResource($request->user()->load('roles', 'psychologist'));
    }

    public function verifyEmail(Request $request)
    {
        $data = $request->validate([
            'uid' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'exp' => ['required', 'integer'],
            'sig' => ['required', 'string'],
        ]);
        $user = $this->auth->verifyEmail($data['uid'], $data['email'], (int) $data['exp'], $data['sig']);

        return response()->json(['ok' => true, 'email' => $user->email]);
    }

    /** P-EMAIL-RESEND-INTERVAL between letters. */
    public function resendVerification(Request $request)
    {
        $user = $request->user();
        if ($user->email_verified_at) {
            return response()->json(['ok' => true, 'already_verified' => true]);
        }
        $key = 'verify-resend:'.$user->id;
        if (Cache::has($key)) {
            throw ValidationException::withMessages(['email' => 'Письмо уже отправлено. Повторить можно через минуту.']);
        }
        Cache::put($key, true, Settings::int('P-EMAIL-RESEND-INTERVAL'));
        $this->auth->sendVerification($user);

        return response()->json(['ok' => true]);
    }

    /** Always answers OK so the endpoint can't be used to probe which emails are registered. */
    public function forgotPassword(Request $request, Notifier $notifier)
    {
        $email = mb_strtolower(trim($request->validate(['email' => ['required', 'email']])['email']));
        $user = User::where('email', $email)->whereIn('status', [User::STATUS_ACTIVE, User::STATUS_PENDING_DELETION])->first();
        if ($user) {
            $token = Password::broker()->createToken($user);
            $url = rtrim((string) config('app.frontend_url'), '/').'/auth/reset?'.http_build_query(['token' => $token, 'email' => $user->email]);
            $notifier->send($user, 'auth.reset_password', [], $url, 'Задать новый пароль');
        }

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $resetUser = null;
        $status = Password::broker()->reset(
            ['email' => mb_strtolower($data['email']), 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation')],
            function (User $user, string $password) use (&$resetUser) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'failed_logins' => 0, 'locked_until' => null])->save();
                $user->tokens()->delete();
                // Following a link from the letter proves ownership of the address.
                if (! $user->email_verified_at) {
                    $user->forceFill(['email_verified_at' => now()])->save();
                }
                $resetUser = $user;
            },
        );
        if ($status !== Password::PASSWORD_RESET || ! $resetUser) {
            throw ValidationException::withMessages(['token' => 'Ссылка для сброса пароля недействительна или устарела.']);
        }
        abort_if($resetUser->status === User::STATUS_BLOCKED, 403, 'Аккаунт заблокирован.');

        return response()->json(['user' => new UserResource($resetUser->load('roles')), 'token' => $this->auth->issueToken($resetUser)]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $data['password']])->save();
        $current = $user->currentAccessToken()?->id;
        $user->tokens()->when($current, fn ($q) => $q->whereKeyNot($current))->delete();

        return response()->json(['ok' => true]);
    }

    public function changeEmail(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'current_password:sanctum'],
        ]);
        $this->auth->requestEmailChange($request->user(), mb_strtolower($data['email']));

        return response()->json(['ok' => true]);
    }

    /** Small check for the registration form. */
    public function emailAvailable(Request $request)
    {
        $email = mb_strtolower((string) $request->validate(['email' => ['required', 'email']])['email']);

        return response()->json(['available' => ! User::where('email', $email)->exists()]);
    }

    public function passwordMatches(Request $request): bool
    {
        return Hash::check((string) $request->input('password'), $request->user()->password);
    }
}
