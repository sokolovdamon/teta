<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** ADM-25: filter by user, section and period; CSV export. */
class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = $this->query($request)->paginate(min((int) $request->integer('per_page', 50), 200));

        return response()->json([
            'data' => $logs->getCollection()->map(fn (AuditLog $l) => $this->present($l)),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'per_page' => $logs->perPage(), 'total' => $logs->total()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->query($request);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Пользователь', 'Email', 'Раздел', 'Действие', 'Объект', 'Комментарий', 'IP'], ';');
            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $l) {
                    fputcsv($out, [$l->created_at->toIso8601String(), $l->user?->fullName(), $l->user?->email, $l->section, $l->action, $l->subject_type ? class_basename($l->subject_type).':'.$l->subject_id : '', $l->comment, $l->ip_address], ';');
                }
            });
            fclose($out);
        }, 'audit.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request)
    {
        return AuditLog::query()->with('user')
            ->when($request->query('user_id'), fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->query('user'), fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('email', 'ilike', "%{$v}%")))
            ->when($request->query('section'), fn ($q, $v) => $q->where('section', $v))
            ->when($request->query('action'), fn ($q, $v) => $q->where('action', 'ilike', "%{$v}%"))
            ->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->orderByDesc('created_at');
    }

    private function present(AuditLog $l): array
    {
        return [
            'id' => $l->id,
            'created_at' => $l->created_at?->toIso8601String(),
            'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->fullName(), 'email' => $l->user->email] : null,
            'section' => $l->section,
            'action' => $l->action,
            'subject_type' => $l->subject_type ? class_basename($l->subject_type) : null,
            'subject_id' => $l->subject_id,
            'changes' => $l->changes,
            'comment' => $l->comment,
            'ip_address' => $l->ip_address,
        ];
    }
}
