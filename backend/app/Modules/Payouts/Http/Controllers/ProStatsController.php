<?php

namespace App\Modules\Payouts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Payouts\Models\Accrual;
use App\Modules\Payouts\Models\Payout;
use App\Modules\Payouts\Services\PayeeBalanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** PRO-08: statistics and income — sessions held, accruals with statuses and reasons, payouts, report export. */
class ProStatsController extends Controller
{
    public function summary(Request $request, PayeeBalanceService $balances)
    {
        $user = $request->user();
        [$from, $to] = $this->period($request);
        $psychologistId = $user->psychologist?->id;

        $sessions = $psychologistId
            ? TherapySession::where('psychologist_id', $psychologistId)
                ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
                ->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')
            : collect();

        $accruals = Accrual::where('user_id', $user->id)->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)->get();
        $earned = $accruals->where('amount', '>', 0);
        $accrued = (int) $earned->sum('amount');
        $reversed = (int) $earned->sum('reversed_amount') + (int) abs($accruals->where('kind', Accrual::KIND_CORRECTION)->where('status', '!=', 'reversed')->sum('amount'));
        $paidOut = (int) Payout::where('user_id', $user->id)->where('status', 'paid')->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('amount');

        return response()->json(['data' => [
            'period' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()],
            'sessions' => [
                'held' => (int) ($sessions[TherapySession::HELD] ?? 0),
                'client_no_show' => (int) ($sessions[TherapySession::CLIENT_NO_SHOW] ?? 0),
                'cancelled_by_client' => (int) ($sessions[TherapySession::CANCELLED_BY_CLIENT] ?? 0),
                'cancelled_by_psy' => (int) ($sessions[TherapySession::CANCELLED_BY_PSY] ?? 0),
                'psy_no_show' => (int) ($sessions[TherapySession::PSY_NO_SHOW] ?? 0),
                'tech_issue' => (int) ($sessions[TherapySession::TECH_ISSUE] ?? 0),
                'upcoming' => (int) (($sessions[TherapySession::BOOKED] ?? 0) + ($sessions[TherapySession::PAID] ?? 0)),
            ],
            'totals' => [
                'accrued' => $accrued,
                'reversed' => $reversed,
                'net' => $accrued - $reversed,
                'paid_out' => $paidOut,
                'by_kind' => $earned->groupBy('kind')->map(fn (Collection $g) => ['count' => $g->count(), 'amount' => (int) $g->sum('amount')])->all(),
            ],
            'balance' => PayeeBalanceService::toApi($balances->forUser($user->id)),
        ]]);
    }

    public function accruals(Request $request)
    {
        $user = $request->user();
        $page = $this->query($request, $user)->paginate(min((int) $request->integer('per_page', 25), 100));
        $rows = $this->rows($page->getCollection());

        return response()->json([
            'data' => $rows,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        [$from, $to] = $this->period($request);
        $query = $this->query($request, $user);
        $filename = 'teta-income-'.$from->toDateString().'-'.$to->subDay()->toDateString().'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата начисления', 'Вид', 'Сессия', 'Формат', 'Клиент', 'Цена, ₽', 'Комиссия, %', 'Начислено, ₽', 'Сторно, ₽', 'Итого, ₽', 'Статус', 'Причина', 'Выплата'], ';');
            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($this->rows($chunk) as $r) {
                    fputcsv($out, [
                        $r['occurred_at'] ? CarbonImmutable::parse($r['occurred_at'])->setTimezone(config('platform.timezone'))->format('d.m.Y H:i') : '',
                        $r['kind_label'],
                        $r['session'] ? CarbonImmutable::parse($r['session']['starts_at'])->setTimezone(config('platform.timezone'))->format('d.m.Y H:i') : '',
                        $r['session'] ? ($r['session']['format'] === 'pair' ? 'парная' : 'индивидуальная') : '',
                        $r['session']['client_name'] ?? '',
                        self::rub($r['base_amount']),
                        $r['commission_percent'],
                        self::rub($r['amount']),
                        self::rub($r['reversed_amount']),
                        self::rub($r['net']),
                        $r['status_label'],
                        $r['reason'],
                        $r['payout'] && $r['payout']['paid_at'] ? CarbonImmutable::parse($r['payout']['paid_at'])->setTimezone(config('platform.timezone'))->format('d.m.Y') : '',
                    ], ';');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [from, to) in the user's timezone */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(array_keys(Accrual::STATUS_LABELS))],
            'kind' => ['nullable', Rule::in(array_keys(Accrual::KIND_LABELS))],
        ]);
        $tz = $request->user()->timezone ?: config('platform.timezone');
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from'), $tz)->startOfDay() : CarbonImmutable::now($tz)->startOfMonth();
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to'), $tz)->startOfDay()->addDay() : CarbonImmutable::now($tz)->startOfDay()->addDay();

        return [$from, $to];
    }

    private function query(Request $request, User $user): Builder
    {
        [$from, $to] = $this->period($request);

        return Accrual::where('user_id', $user->id)
            ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->with('payout')
            ->orderByDesc('occurred_at')->orderByDesc('created_at');
    }

    /** @param  Collection<int, Accrual>  $accruals */
    private function rows(Collection $accruals): array
    {
        $clientIds = $accruals->map(fn (Accrual $a) => $a->meta['client_id'] ?? null)->filter()->unique()->values();
        $clients = $clientIds->isEmpty() ? collect() : User::withTrashed()->whereIn('id', $clientIds)->get(['id', 'name', 'last_name'])->keyBy('id');

        return $accruals->map(function (Accrual $a) use ($clients) {
            $meta = $a->meta ?? [];
            $client = isset($meta['client_id']) ? $clients->get($meta['client_id']) : null;

            return [
                'id' => $a->id,
                'kind' => $a->kind,
                'kind_label' => Accrual::KIND_LABELS[$a->kind] ?? $a->kind,
                'status' => $a->status,
                'status_label' => Accrual::STATUS_LABELS[$a->status] ?? $a->status,
                'occurred_at' => $a->occurred_at?->toIso8601String(),
                'base_amount' => (int) $a->base_amount,
                'commission_percent' => (int) $a->commission_percent,
                'amount' => (int) $a->amount,
                'reversed_amount' => (int) $a->reversed_amount,
                'net' => $a->net(),
                'reason' => $a->reason,
                'adjustments' => collect($a->adjustments ?? [])->map(fn ($j) => [
                    'type' => $j['type'] ?? 'reversal', 'amount' => (int) ($j['amount'] ?? 0), 'reason' => $j['reason'] ?? null, 'at' => $j['at'] ?? null,
                ])->values()->all(),
                'correction_of_id' => $a->correction_of_id,
                'session' => isset($meta['session_starts_at']) ? [
                    'id' => $meta['session_id'] ?? null,
                    'starts_at' => $meta['session_starts_at'],
                    'format' => $meta['format'] ?? 'individual',
                    'client_name' => $client ? trim($client->name.' '.mb_substr((string) $client->last_name, 0, 1).($client->last_name ? '.' : '')) : null,
                    'corporate' => (bool) ($meta['corporate'] ?? false),
                ] : null,
                'payout' => $a->payout ? ['id' => $a->payout->id, 'status' => $a->payout->status, 'paid_at' => $a->payout->paid_at?->toIso8601String()] : null,
            ];
        })->values()->all();
    }

    private static function rub(int $kopecks): string
    {
        return number_format($kopecks / 100, 2, ',', '');
    }
}
