<?php

namespace App\Modules\Diary\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Services\ClientRelationship;
use App\Modules\Diary\Services\DiaryService;
use Illuminate\Http\Request;

/**
 * PRO-06: diary dynamics of a client for their psychologist (DEC-41, DM-08) — mood and tags aggregates only,
 * never the client's notes. A hard restriction in code: no role or permission opens someone else's client.
 */
class PsychologistDiaryController extends Controller
{
    public function __construct(private DiaryService $diary, private ClientRelationship $relationship) {}

    public function dynamics(Request $request, string $client)
    {
        $data = DiaryController::periodInput($request);
        $psychologist = ClientRelationship::psychologistOf($request->user());
        $this->relationship->ensureClientOf($psychologist, $client);

        $access = $this->relationship->diaryAccess($psychologist, $client);
        abort_unless($access !== null, 403, 'Динамика дневника доступна психологу, к которому клиент записан или у которого проходил сессии.');

        $user = User::withTrashed()->findOrFail($client);
        [$from, $to, $group] = $this->diary->period($user, $data['period'] ?? null, $data['from'] ?? null, $data['to'] ?? null, $data['group'] ?? null);

        if ($access->until !== null) {
            // The previous psychologist sees the last period of the work, not an empty current one.
            $lastVisibleDay = $access->until->setTimezone($this->diary->timezone($user))->startOfDay();
            if ($to->greaterThan($lastVisibleDay)) {
                $shift = (int) round(abs($lastVisibleDay->diffInDays($to)));
                $to = $lastVisibleDay;
                $from = isset($data['from']) ? $from : $from->subDays($shift);
                if ($from->greaterThan($to)) {
                    $from = $to;
                }
            }
        }

        return response()->json(['data' => [
            ...$this->diary->dynamics($client, $from, $to, $group, $access->until),
            'access' => $access->toApi(),
        ]]);
    }
}
