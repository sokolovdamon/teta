<?php

namespace App\Modules\Account\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Auth\Http\Resources\UserResource;
use App\Modules\Notifications\Notifier;
use App\Support\Events\Outbox;
use App\Support\Settings\Settings;
use Illuminate\Http\Request;

/** CL-08 / PRO-10: profile, timezone, account deletion on request (SEQ-22). */
class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'gender' => ['nullable', 'in:male,female'],
            'timezone' => ['sometimes', 'timezone:all'],
        ]);
        $user = $request->user();
        $user->update($data);

        return new UserResource($user->load('roles'));
    }

    /** The account is deleted after P-DELETE-GRACE; until then the request can be cancelled. */
    public function requestDeletion(Request $request, Notifier $notifier)
    {
        $request->validate(['password' => ['required', 'current_password:sanctum']]);
        $user = $request->user();
        $user->forceFill(['status' => User::STATUS_PENDING_DELETION, 'deletion_requested_at' => now()])->save();
        $date = now()->addDays(Settings::int('P-DELETE-GRACE'));
        Outbox::record('account.deletion_requested', $user, ['delete_at' => $date->toIso8601String()], $user->id);
        Audit::log('CL-08', 'account.deletion_requested', $user, null, null, $user->id);
        $notifier->send($user, 'account.deletion_requested', ['date' => $date->locale('ru')->translatedFormat('j F Y')]);

        return response()->json(['ok' => true, 'delete_at' => $date->toIso8601String()]);
    }

    public function cancelDeletion(Request $request)
    {
        $user = $request->user();
        abort_unless($user->status === User::STATUS_PENDING_DELETION, 422, 'Удаление аккаунта не запрошено.');
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'deletion_requested_at' => null])->save();
        Outbox::record('account.deletion_cancelled', $user, [], $user->id);
        Audit::log('CL-08', 'account.deletion_cancelled', $user, null, null, $user->id);

        return response()->json(['ok' => true]);
    }
}
