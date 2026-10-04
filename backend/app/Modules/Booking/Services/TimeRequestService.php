<?php

namespace App\Modules\Booking\Services;

use App\Models\User;
use App\Modules\Booking\Models\SessionTimeRequest;
use App\Modules\Booking\Support\BookingError;
use App\Modules\Booking\Support\SessionTime;
use App\Modules\Notifications\Notifier;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Schedule\Services\SlotService;
use App\Support\Events\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Нет подходящего времени" (DEC-28): a structured request instead of messaging the psychologist.
 * open → offered (the psychologist opened slots and offered them) | closed; → booked when the client books.
 */
class TimeRequestService
{
    public const WEEKDAYS = [1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс'];

    public function __construct(private Notifier $notifier, private SlotService $slots) {}

    /** @param  array{psychologist_id: string, format?: string, preferred: list<array{weekday?: int, date?: string, from: string, to: string}>, comment?: string|null}  $data */
    public function create(User $client, array $data): SessionTimeRequest
    {
        $p = Psychologist::findOrFail($data['psychologist_id']);
        $open = SessionTimeRequest::where('client_id', $client->id)->where('psychologist_id', $p->id)->whereIn('status', ['open', 'offered'])->exists();
        if ($open) {
            BookingError::fail('Запрос этому психологу уже отправлен — дождитесь ответа.', 'duplicate', 'psychologist_id');
        }
        $request = DB::transaction(function () use ($client, $p, $data) {
            $r = new SessionTimeRequest;
            $r->forceFill([
                'client_id' => $client->id,
                'psychologist_id' => $p->id,
                'format' => $data['format'] ?? 'individual',
                'preferred' => array_values($data['preferred']),
                'comment' => $data['comment'] ?? null,
                'status' => 'open',
            ])->save();
            Outbox::record('book.time_request.created', $r, ['psychologist_id' => $p->id, 'client_id' => $client->id], $client->id);

            return $r;
        });
        if ($user = $p->user) {
            $this->notifier->send($user, 'book.psy_time_request', [
                'client' => $client->name,
                'preferred' => $this->preferredText($request->preferred ?? [], $client->timezone),
            ], '/pro?tab=requests');
        }

        return $request;
    }

    public function cancel(SessionTimeRequest $r, User $client): SessionTimeRequest
    {
        abort_unless($r->client_id === $client->id, 404);
        if (in_array($r->status, ['open', 'offered'], true)) {
            $r->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
            Outbox::record('book.time_request.closed', $r, ['by' => 'client'], $client->id);
        }

        return $r;
    }

    /** @param  list<string>  $slots  ISO datetimes the psychologist opened in the schedule */
    public function offer(SessionTimeRequest $r, Psychologist $p, array $slots, ?string $comment): SessionTimeRequest
    {
        abort_unless($r->psychologist_id === $p->id, 404);
        if (! in_array($r->status, ['open', 'offered'], true)) {
            BookingError::fail('Запрос уже закрыт.', 'closed', 'status', 409);
        }
        $valid = [];
        foreach ($slots as $slot) {
            $at = CarbonImmutable::parse($slot)->utc()->startOfMinute();
            if (! $this->slots->isAvailable($p, $at, $r->format ?? 'individual')) {
                BookingError::fail('Время '.SessionTime::format($at, $p->timezone).' недоступно для записи: откройте его в графике работы.', 'slot_unavailable', 'slots');
            }
            $valid[] = $at->toIso8601String();
        }
        if ($valid === [] && ! $comment) {
            BookingError::fail('Предложите время или напишите комментарий.', 'empty_offer', 'slots');
        }
        $r->forceFill(['status' => 'offered', 'offered_slots' => $valid, 'psychologist_comment' => $comment, 'answered_at' => now()])->save();
        Outbox::record('book.time_request.offered', $r, ['slots' => $valid], $p->user_id);

        $client = User::find($r->client_id);
        if ($client) {
            $offer = $valid ? 'Свободное время: '.implode('; ', array_map(fn ($s) => SessionTime::format($s, $client->timezone), $valid)).'.' : '';
            $this->notifier->send($client, 'book.time_request_offered', [
                'psychologist' => $p->fullName(),
                'offer' => trim($offer.' '.($comment ?? '')),
            ], '/psychologists/'.$p->slug);
        }

        return $r;
    }

    public function close(SessionTimeRequest $r, Psychologist $p, ?string $comment): SessionTimeRequest
    {
        abort_unless($r->psychologist_id === $p->id, 404);
        if (in_array($r->status, ['open', 'offered'], true)) {
            $r->forceFill(['status' => 'closed', 'psychologist_comment' => $comment, 'closed_at' => now(), 'answered_at' => $r->answered_at ?? now()])->save();
            Outbox::record('book.time_request.closed', $r, ['by' => 'psychologist'], $p->user_id);
            if ($client = User::find($r->client_id)) {
                $this->notifier->send($client, 'book.time_request_closed', ['psychologist' => $p->fullName(), 'comment' => $comment ?? ''], '/client/sessions');
            }
        }

        return $r;
    }

    /** @param  list<array<string, mixed>>  $preferred */
    public function preferredText(array $preferred, ?string $tz = null): string
    {
        $parts = [];
        foreach ($preferred as $p) {
            $day = isset($p['date']) ? SessionTime::date($p['date'], $tz) : (self::WEEKDAYS[(int) ($p['weekday'] ?? 0)] ?? 'любой день');
            $parts[] = $day.' '.($p['from'] ?? '').'–'.($p['to'] ?? '');
        }

        return $parts ? implode(', ', $parts).($tz ? ' ('.SessionTime::zoneLabel($tz).')' : '') : 'не указано';
    }

    public function toApi(SessionTimeRequest $r, ?string $tz = null, bool $forPsychologist = false): array
    {
        $p = $r->relationLoaded('psychologist') ? $r->psychologist : Psychologist::withTrashed()->find($r->psychologist_id);

        return [
            'id' => $r->id,
            'status' => $r->status,
            'format' => $r->format,
            'preferred' => $r->preferred ?? [],
            'preferred_text' => $this->preferredText($r->preferred ?? [], $forPsychologist ? User::find($r->client_id)?->timezone : $tz),
            'comment' => $r->comment,
            'psychologist_comment' => $r->psychologist_comment,
            'offered_slots' => $r->offered_slots ?? [],
            'psychologist' => $p ? ['id' => $p->id, 'slug' => $p->slug, 'name' => $p->fullName()] : null,
            'client' => $forPsychologist ? ['name' => User::find($r->client_id)?->name] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'answered_at' => $r->answered_at?->toIso8601String(),
        ];
    }
}
