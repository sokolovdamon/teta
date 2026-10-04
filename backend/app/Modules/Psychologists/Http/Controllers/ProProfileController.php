<?php

namespace App\Modules\Psychologists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\PsychologistPresenter;
use App\Modules\Psychologists\Http\PsychologistViews;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Services\ProfileService;
use App\Modules\Psychologists\Services\WorkStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PRO-02: own profile, photo, video card (DEC-44), prices; pause and resume of new bookings (BR-PSY-06). */
class ProProfileController extends Controller
{
    public function __construct(private ProfileService $profile, private PsychologistViews $views)
    {
        PsychologistPresenter::flush();
    }

    public static function own(Request $request): Psychologist
    {
        $p = $request->user()->psychologist;
        abort_unless($p, 404, 'Профиль психолога не найден.');

        return $p;
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->views->own(self::own($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $p = self::own($request);
        $year = (int) now()->year;
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:80'],
            'last_name' => ['sometimes', 'required', 'string', 'max:80'],
            'gender' => ['sometimes', 'nullable', Rule::in(['female', 'male'])],
            'birth_year' => ['sometimes', 'nullable', 'integer', 'min:'.($year - 90), 'max:'.($year - 21)],
            'headline' => ['sometimes', 'nullable', 'string', 'max:200'],
            'about' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:70'],
            'education' => ['sometimes', 'array', 'max:15'],
            'education.*.institution' => ['required', 'string', 'max:255'],
            'education.*.specialty' => ['nullable', 'string', 'max:255'],
            'education.*.year' => ['nullable', 'integer', 'min:1950', 'max:'.$year],
            'approaches' => ['sometimes', 'array', 'max:12'],
            'approaches.*.id' => ['required', 'uuid', 'distinct', Rule::exists('approaches', 'id')->where('is_active', true)],
            'approaches.*.explanation' => ['nullable', 'string', 'max:1000'],
            'specializations' => ['sometimes', 'array', 'max:20'],
            'specializations.*' => ['uuid', 'distinct', Rule::exists('specializations', 'id')->where('is_active', true)],
            'requests' => ['sometimes', 'array', 'max:43'],
            'requests.*' => ['uuid', 'distinct', Rule::exists('client_requests', 'id')],
            'works_individual' => ['sometimes', 'boolean'],
            'works_pair' => ['sometimes', 'boolean'],
            // Kopecks; DEC-19: the psychologist sets the price and thereby the price category (DEC-55).
            'price_individual' => ['sometimes', 'nullable', 'integer', 'min:50000', 'max:5000000'],
            'price_pair' => ['sometimes', 'nullable', 'integer', 'min:50000', 'max:5000000'],
        ], [
            'price_individual.min' => 'Цена не может быть меньше 500 ₽.',
            'price_pair.min' => 'Цена не может быть меньше 500 ₽.',
            'price_individual.max' => 'Цена не может быть больше 50 000 ₽.',
            'price_pair.max' => 'Цена не может быть больше 50 000 ₽.',
        ]);

        $worksIndividual = $data['works_individual'] ?? $p->works_individual;
        $worksPair = $data['works_pair'] ?? $p->works_pair;
        if (! $worksIndividual && ! $worksPair) {
            return response()->json(['message' => 'Выберите хотя бы один формат работы.', 'errors' => ['works_individual' => ['Выберите хотя бы один формат работы.']]], 422);
        }
        foreach (['individual' => $worksIndividual, 'pair' => $worksPair] as $format => $works) {
            $price = array_key_exists("price_{$format}", $data) ? $data["price_{$format}"] : $p->{"price_{$format}"};
            if ($works && $p->qualification_status === 'approved' && ! $price) {
                return response()->json(['message' => 'Укажите цену для выбранного формата.', 'errors' => ["price_{$format}" => ['Укажите цену для выбранного формата.']]], 422);
            }
        }

        $result = $this->profile->update($p, $data, $request->user());

        return response()->json(['data' => $this->views->own($p->fresh()), 'result' => $result]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $p = self::own($request);
        $request->validate(['file' => ['required', 'file', 'image', 'max:10240']]);
        $result = $this->profile->setPhoto($p, $request->file('file'), $request->user());

        return response()->json(['data' => $this->views->own($p->fresh()), 'result' => $result]);
    }

    public function removePhoto(Request $request): JsonResponse
    {
        $p = self::own($request);
        $result = $this->profile->removePhoto($p, $request->user());

        return response()->json(['data' => $this->views->own($p->fresh()), 'result' => $result]);
    }

    public function uploadVideo(Request $request): JsonResponse
    {
        $p = self::own($request);
        $data = $request->validate([
            'file' => ['required', 'file'],
            'duration' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ]);
        $this->profile->uploadVideo($p, $request->file('file'), isset($data['duration']) ? (int) $data['duration'] : null, $request->user());

        return response()->json(['data' => $this->views->own($p->fresh())]);
    }

    public function removeVideo(Request $request): JsonResponse
    {
        $p = self::own($request);
        $this->profile->removeVideo($p, $request->user());

        return response()->json(['data' => $this->views->own($p->fresh())]);
    }

    public function pause(Request $request, WorkStatusService $status): JsonResponse
    {
        $p = self::own($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $status->pause($p, $request->user(), $data['reason'] ?? null);

        return response()->json(['data' => $this->views->own($p->fresh())]);
    }

    public function resume(Request $request, WorkStatusService $status): JsonResponse
    {
        $p = self::own($request);
        $status->resume($p, $request->user());

        return response()->json(['data' => $this->views->own($p->fresh())]);
    }
}
