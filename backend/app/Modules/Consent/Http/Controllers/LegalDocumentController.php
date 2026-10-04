<?php

namespace App\Modules\Consent\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Audit;
use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\LegalDocument;
use App\Modules\Consent\Models\LegalDocumentVersion;
use App\Modules\Consent\Services\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LegalDocumentController extends Controller
{
    /** SITE-17: public list of documents. */
    public function index()
    {
        $docs = LegalDocument::where('is_public', true)->with('currentVersion')->orderBy('title')->get();

        return response()->json(['data' => $docs->filter(fn ($d) => $d->currentVersion)->map(fn ($d) => [
            'slug' => $d->slug, 'title' => $d->title, 'kind' => $d->kind,
            'version' => $d->currentVersion->version, 'published_at' => $d->currentVersion->published_at->toIso8601String(),
        ])->values()]);
    }

    /** SITE-17: /legal/{doc} */
    public function show(string $slug)
    {
        $doc = LegalDocument::where('slug', $slug)->where('is_public', true)->with('currentVersion')->firstOrFail();
        abort_unless($doc->currentVersion, 404);

        return response()->json(['data' => [
            'slug' => $doc->slug, 'title' => $doc->title, 'kind' => $doc->kind,
            'version' => $doc->currentVersion->version, 'published_at' => $doc->currentVersion->published_at->toIso8601String(),
            'body' => $doc->currentVersion->body,
        ]]);
    }

    /** CL-08: the user's own consents. */
    public function myConsents(Request $request)
    {
        $consents = Consent::where('user_id', $request->user()->id)->with('version.document')->latest('accepted_at')->get();

        return response()->json(['data' => $consents->map(fn (Consent $c) => [
            'id' => $c->id, 'purpose' => $c->purpose, 'document' => $c->version->document->title,
            'slug' => $c->version->document->slug, 'version' => $c->version->version,
            'accepted_at' => $c->accepted_at->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String(),
        ])]);
    }

    /** Optional consents (mailing, cookies) can be given or revoked at any time. */
    public function toggle(Request $request, ConsentService $consents)
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in([LegalDocument::MAILING, LegalDocument::COOKIES])],
            'accepted' => ['required', 'boolean'],
        ]);
        $user = $request->user();
        if ($data['accepted']) {
            if (! $consents->has($user, $data['purpose'])) {
                $consents->accept($user, $data['purpose']);
            }
        } else {
            $consents->revoke($user, $data['purpose']);
        }

        return response()->json(['ok' => true]);
    }

    // ---- ADM-20: documents and versions --------------------------------------------------------

    public function adminIndex()
    {
        return response()->json(['data' => LegalDocument::with('versions')->orderBy('title')->get()]);
    }

    public function adminStore(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:128', 'regex:/^[a-z0-9-]+$/', Rule::unique('legal_documents', 'slug')],
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'string', 'max:64'],
            'requires_consent' => ['boolean'],
            'is_public' => ['boolean'],
        ]);
        $doc = LegalDocument::create($data);
        Audit::log('ADM-20', 'legal_document.created', $doc);

        return response()->json(['data' => $doc], 201);
    }

    public function adminUpdate(Request $request, LegalDocument $document)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'requires_consent' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
        ]);
        $document->update($data);
        Audit::log('ADM-20', 'legal_document.updated', $document, $data);

        return response()->json(['data' => $document]);
    }

    /** A new version; consents always reference the exact version accepted. */
    public function adminAddVersion(Request $request, LegalDocument $document)
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', Rule::unique('legal_document_versions', 'version')->where('legal_document_id', $document->id)],
            'body' => ['required', 'string'],
            'published_at' => ['nullable', 'date'],
        ]);
        $version = $document->versions()->create([...$data, 'created_by' => $request->user()->id]);
        Audit::log('ADM-20', 'legal_document.version_added', $document, ['version' => $version->version]);

        return response()->json(['data' => $version], 201);
    }

    public function adminPublishVersion(LegalDocumentVersion $version)
    {
        $version->update(['published_at' => now()]);
        Audit::log('ADM-20', 'legal_document.version_published', $version->document, ['version' => $version->version]);

        return response()->json(['data' => $version]);
    }

    /** ADM-06: consents of a given purpose (e.g. review publication) for viewing and printing. */
    public function adminConsents(Request $request)
    {
        $consents = Consent::query()->with(['user', 'version.document'])
            ->when($request->query('purpose'), fn ($q, $v) => $q->where('purpose', $v))
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->latest('accepted_at')->paginate(50);

        return response()->json([
            'data' => $consents->getCollection()->map(fn (Consent $c) => [
                'id' => $c->id, 'purpose' => $c->purpose, 'user' => $c->user ? ['id' => $c->user->id, 'name' => $c->user->fullName(), 'email' => $c->user->email] : null,
                'email' => $c->email, 'document' => $c->version->document->title, 'version' => $c->version->version,
                'subject_type' => $c->subject_type ? class_basename($c->subject_type) : null, 'subject_id' => $c->subject_id,
                'accepted_at' => $c->accepted_at->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String(), 'ip_address' => $c->ip_address,
            ]),
            'meta' => ['current_page' => $consents->currentPage(), 'last_page' => $consents->lastPage(), 'total' => $consents->total(), 'per_page' => $consents->perPage()],
        ]);
    }
}
