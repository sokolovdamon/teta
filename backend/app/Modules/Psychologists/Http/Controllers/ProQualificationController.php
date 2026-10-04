<?php

namespace App\Modules\Psychologists\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Psychologists\Http\PsychologistViews;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Modules\Psychologists\Services\QualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** PRO-01: documents, submission for review and the history of decisions (ST-08, SEQ-12). */
class ProQualificationController extends Controller
{
    public function __construct(private QualificationService $qualification, private PsychologistViews $views) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->views->qualification(ProProfileController::own($request))]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $data = $request->validate([
            'file' => ['required', 'file'],
            'kind' => ['required', Rule::in(Psychologist::DOCUMENT_KINDS)],
            'title' => ['required', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'specialty' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
        ]);
        $this->qualification->addDocument($p, $request->file('file'), $data, $request->user());

        return response()->json(['data' => $this->views->qualification($p->fresh())], 201);
    }

    public function destroyDocument(Request $request, QualificationDocument $document): JsonResponse
    {
        $p = ProProfileController::own($request);
        abort_unless($document->psychologist_id === $p->id, 404);
        $this->qualification->deleteDocument($p, $document);

        return response()->json(['data' => $this->views->qualification($p->fresh())]);
    }

    public function submit(Request $request): JsonResponse
    {
        $p = ProProfileController::own($request);
        $this->qualification->submit($p, $request->user());

        return response()->json(['data' => $this->views->qualification($p->fresh())]);
    }
}
