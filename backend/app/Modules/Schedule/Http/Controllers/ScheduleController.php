<?php

namespace App\Modules\Schedule\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Psychologists\Http\Controllers\ProProfileController;
use App\Modules\Schedule\Models\ScheduleException;
use App\Modules\Schedule\Models\ScheduleInterval;
use App\Modules\Schedule\Services\ScheduleService;
use App\Support\Settings\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PRO-03: working intervals, vacations and blocked dates, timezone and personal limits, preview of free slots. */
class ScheduleController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->schedule->overview(ProProfileController::own($request))]);
    }

    public function replaceIntervals(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'intervals' => ['present', 'array', 'max:56'],
            'intervals.*.weekday' => ['required', 'integer', 'between:1,7'],
            'intervals.*.starts_at' => ['required', 'string', 'max:8'],
            'intervals.*.ends_at' => ['required', 'string', 'max:8'],
        ]);
        $this->schedule->replaceIntervals($p, $data['intervals']);

        return response()->json(['data' => $this->schedule->overview($p->fresh())]);
    }

    public function storeInterval(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'string', 'max:8'],
            'ends_at' => ['required', 'string', 'max:8'],
        ]);
        $interval = $this->schedule->addInterval($p, $data);

        return response()->json(['data' => $this->schedule->intervalRow($interval)], 201);
    }

    public function updateInterval(Request $request, ScheduleInterval $interval): JsonResponse
    {
        $p = ProProfileController::own($request);
        abort_unless($interval->psychologist_id === $p->id, 404);
        $data = $request->validate([
            'weekday' => ['sometimes', 'integer', 'between:1,7'],
            'starts_at' => ['sometimes', 'string', 'max:8'],
            'ends_at' => ['sometimes', 'string', 'max:8'],
        ]);

        return response()->json(['data' => $this->schedule->intervalRow($this->schedule->updateInterval($p, $interval, $data))]);
    }

    public function destroyInterval(Request $request, ScheduleInterval $interval): JsonResponse
    {
        $p = ProProfileController::own($request);
        abort_unless($interval->psychologist_id === $p->id, 404);
        $this->schedule->deleteInterval($p, $interval);

        return response()->json(['data' => $this->schedule->overview($p->fresh())]);
    }

    public function storeException(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['vacation', 'blocked'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);
        $exception = $this->schedule->addException($p, $data);

        return response()->json(['data' => $this->schedule->exceptionRow($exception)], 201);
    }

    public function destroyException(Request $request, ScheduleException $exception): JsonResponse
    {
        $p = ProProfileController::own($request);
        abort_unless($exception->psychologist_id === $p->id, 404);
        $this->schedule->deleteException($p, $exception);

        return response()->json(['data' => $this->schedule->overview($p->fresh())]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'min_lead_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:20160'],
            'horizon_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            'buffer_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:120'],
        ]);
        $this->schedule->updateSettings($p, $data);

        return response()->json(['data' => $this->schedule->overview($p->fresh())]);
    }

    public function preview(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['individual', 'pair'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:'.Settings::int('P-BOOK-HORIZON')],
        ]);
        $format = $data['format'] ?? ($p->works_individual ? 'individual' : 'pair');

        return response()->json(['data' => [
            'format' => $format,
            'timezone' => $p->timezone,
            'slots' => $this->schedule->preview($p, $format, (int) ($data['days'] ?? 14)),
        ]]);
    }
}
