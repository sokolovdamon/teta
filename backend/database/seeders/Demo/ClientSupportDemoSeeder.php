<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Booking\Models\TherapySession;
use App\Modules\Crm\Models\PsychologistNote;
use App\Modules\Diary\Models\DiaryEntry;
use App\Modules\Diary\Models\EmotionTag;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Recommendations\Models\Recommendation;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Stream E demo (dev and stage): client@teta.local works with Анна Соколова — held sessions, an upcoming one,
 * a month of diary entries, recommendations and a private note. Idempotent: skipped when the client has a diary.
 */
class ClientSupportDemoSeeder extends Seeder
{
    public function run(): void
    {
        $client = User::where('email', 'client@teta.local')->first();
        $user = User::where('email', 'anna.sokolova@teta.local')->first();
        $psychologist = $user ? Psychologist::where('user_id', $user->id)->first() : null;
        if (! $client || ! $psychologist || DiaryEntry::where('client_id', $client->id)->exists()) {
            return;
        }

        $now = CarbonImmutable::now('Europe/Moscow');
        $requests = ClientRequest::whereIn('slug', ['trevoga', 'vygoranie'])->where('format', 'individual')->pluck('id')->all();
        $held = [];
        foreach ([21, 14, 7] as $daysAgo) {
            $held[] = $this->session($psychologist, $client, $now->subDays($daysAgo)->setTime(19, 0), TherapySession::HELD, $requests);
        }
        $this->session($psychologist, $client, $now->addDays(2)->setTime(19, 0), TherapySession::PAID, $requests);

        $tags = EmotionTag::pluck('id', 'code');
        $pattern = [[2, ['trevoga', 'ustalost']], [3, ['trevoga']], [3, ['spokoystvie']], [4, ['interes']], [2, ['ustalost', 'grust']], [4, ['radost', 'nadezhda']], [5, ['radost', 'blagodarnost']]];
        for ($day = 29; $day >= 1; $day--) {
            if ($day % 4 === 0) {
                continue;
            }
            [$mood, $codes] = $pattern[$day % count($pattern)];
            $at = $now->subDays($day)->setTime(9 + $day % 10, 15);
            DiaryEntry::create([
                'client_id' => $client->id,
                'mood' => min(5, $mood + ($day < 10 ? 1 : 0)),
                'tag_ids' => collect($codes)->map(fn ($c) => $tags[$c] ?? null)->filter()->values()->all() ?: null,
                'note' => $day === 3 ? 'Получилось спокойно поговорить с руководителем.' : null,
                'recorded_at' => $at,
                'local_date' => $at->toDateString(),
            ]);
        }

        $last = end($held);
        $sent = new Recommendation([
            'psychologist_id' => $psychologist->id, 'client_id' => $client->id, 'session_id' => $last->id,
            'type' => 'exercise', 'title' => 'Дыхание 4-7-8 перед сном',
            'body' => "Вдох через нос на 4 счёта, задержка на 7, выдох через рот на 8.\nПовторяйте 4 цикла каждый вечер в течение недели и отмечайте в дневнике, как спалось.",
            'links' => [['url' => 'https://www.who.int/ru/news-room/fact-sheets/detail/mental-health-strengthening-our-response', 'title' => 'ВОЗ о психическом здоровье', 'kb_material_id' => null]],
            'due_date' => $now->addDays(5)->toDateString(), 'window_until' => $last->ends_at->addDays(Settings::int('P-RECO-WINDOW')), 'sent_at' => $now->subDays(6),
        ]);
        $sent->forceFill(['status' => Recommendation::SENT])->save();

        $draft = new Recommendation([
            'psychologist_id' => $psychologist->id, 'client_id' => $client->id, 'session_id' => $last->id,
            'type' => 'task', 'title' => 'Дневник автоматических мыслей',
            'body' => 'Когда замечаете тревогу, записывайте ситуацию, мысль и что вы почувствовали.',
            'window_until' => $last->ends_at->addDays(Settings::int('P-RECO-WINDOW')),
        ]);
        $draft->forceFill(['status' => Recommendation::DRAFT])->save();

        PsychologistNote::create([
            'psychologist_id' => $psychologist->id, 'client_id' => $client->id, 'session_id' => $last->id,
            'body' => "Тревога снижается, сон лучше. Обсудили границы на работе.\nПлан: закрепить дыхательную практику, вернуться к теме выгорания.",
        ]);
    }

    /** @param  list<string>  $requests */
    private function session(Psychologist $psychologist, User $client, CarbonImmutable $start, string $status, array $requests): TherapySession
    {
        $session = new TherapySession([
            'client_id' => $client->id, 'psychologist_id' => $psychologist->id, 'format' => 'individual',
            'starts_at' => $start, 'ends_at' => $start->addMinutes(50), 'duration_min' => 50,
            'price' => $psychologist->price_individual, 'amount_due' => $psychologist->price_individual,
            'paid_at' => $start->subHours(12), 'payment_source' => 'card', 'client_timezone' => 'Europe/Moscow',
            'client_request_ids' => $requests, 'source' => 'catalog',
            'created_at' => $start->subDays(3), 'updated_at' => $start->subDays(3),
        ]);
        $session->forceFill(['status' => $status])->save();

        return $session;
    }
}
