<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Corporate\Models\Company;
use App\Modules\Corporate\Models\CorporateProgram;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Rbac\Services\RbacService;
use App\Modules\Schedule\Models\ScheduleInterval;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for dev and stage. All demo accounts use the password "teta12345".
 * super@teta.local (super_admin), admin@teta.local (admin), client@teta.local, hr@teta.local,
 * anna.sokolova@teta.local … (psychologists), supervisor@teta.local (psychologist + supervisor).
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'teta12345';

    private const PSYCHOLOGISTS = [
        ['Анна', 'Соколова', 'female', 1986, 9, 300000, null, ['kpt', 'act'], ['trevoga', 'samoocenka', 'vygoranie'], 'Помогаю справляться с тревогой и выгоранием, возвращать опору и энергию.'],
        ['Михаил', 'Орлов', 'male', 1980, 14, 450000, 650000, ['sistemnaya-semeynaya', 'efs'], ['otnosheniya', 'para', 'krizisy'], 'Работаю с парами и с теми, кто переживает кризис в отношениях.'],
        ['Екатерина', 'Белова', 'female', 1990, 6, 380000, null, ['geshtalt', 'telesno-orientirovannaya'], ['samoocenka', 'otnosheniya', 'travma'], 'Помогаю лучше понимать свои чувства и строить отношения без потери себя.'],
        ['Дмитрий', 'Ковалёв', 'male', 1984, 11, 600000, 800000, ['psihodinamicheskiy', 'shema-terapiya'], ['travma', 'otnosheniya', 'zavisimosti'], 'Работаю с повторяющимися сценариями в жизни и отношениях, с травматическим опытом.'],
        ['Ольга', 'Миронова', 'female', 1979, 17, 550000, 750000, ['efs', 'klient-centrirovannyy'], ['para', 'roditelstvo', 'krizisy'], 'Поддерживаю семьи и пары в периоды перемен: рождение детей, развод, утрата.'],
        ['Илья', 'Зайцев', 'male', 1992, 5, 320000, null, ['kpt', 'narrativnaya'], ['trevoga', 'vygoranie', 'depressivnye'], 'Помогаю с прокрастинацией, мотивацией и поиском своего дела.'],
        ['Наталья', 'Громова', 'female', 1988, 8, 420000, null, ['ekzistencialnyy', 'act'], ['krizisy', 'depressivnye', 'samoocenka'], 'Работаю с потерей смысла, одиночеством и переживанием утраты.'],
        ['Светлана', 'Лебедева', 'female', 1983, 12, 480000, null, ['kpt', 'shema-terapiya'], ['rpp', 'trevoga', 'samoocenka'], 'Специализируюсь на пищевом поведении, панических атаках и самооценке.'],
    ];

    public function run(): void
    {
        $rbac = app(RbacService::class);

        $this->user('super@teta.local', 'Супер', 'Админ', ['super_admin']);
        $this->user('admin@teta.local', 'Алина', 'Администратор', ['admin']);
        $this->user('client@teta.local', 'Иван', 'Клиентов', ['client']);
        $hr = $this->user('hr@teta.local', 'Елена', 'Кадрова', ['hr']);

        $company = Company::updateOrCreate(['name' => 'ООО «Ромашка»'], ['legal_name' => 'Общество с ограниченной ответственностью «Ромашка»', 'inn' => '7700000000', 'contact_email' => 'hr@romashka.example', 'status' => 'active']);
        DB::table('company_user')->updateOrInsert(['company_id' => $company->id, 'user_id' => $hr->id], ['created_at' => now()]);
        CorporateProgram::updateOrCreate(['code' => 'ROMASHKA2026'], [
            'company_id' => $company->id, 'title' => 'Психологическая поддержка сотрудников', 'email_domains' => ['romashka.example'],
            'sessions_limit' => 4, 'limit_period' => 'month', 'company_session_price' => 400000, 'allowed_formats' => ['individual'],
            'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(), 'status' => 'active',
        ]);

        foreach (self::PSYCHOLOGISTS as $i => $p) {
            $email = mb_strtolower(transliterator_transliterate('Russian-Latin/BGN; Latin-ASCII', $p[0].'.'.$p[1])).'@teta.local';
            $user = $this->user(str_replace(["'", 'ʹ'], '', $email), $p[0], $p[1], ['psychologist']);
            $this->psychologist($user, $p, $i);
        }

        $supervisor = $this->user('supervisor@teta.local', 'Татьяна', 'Супервизорова', ['psychologist', 'supervisor']);
        $this->psychologist($supervisor, ['Татьяна', 'Супервизорова', 'female', 1975, 22, 700000, 900000, ['psihodinamicheskiy', 'geshtalt'], ['travma', 'krizisy'], 'Супервизор и практикующий психолог с 20-летним стажем.'], 8);
        $rbac->assignRole($supervisor, 'supervisor');
    }

    private function user(string $email, string $name, string $lastName, array $roles): User
    {
        $user = User::withTrashed()->updateOrCreate(['email' => $email], [
            'name' => $name, 'last_name' => $lastName, 'password' => self::PASSWORD,
            'birth_date' => '1990-01-01', 'timezone' => 'Europe/Moscow',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'status' => User::STATUS_ACTIVE])->save();
        foreach ($roles as $role) {
            app(RbacService::class)->assignRole($user, $role);
        }

        return $user;
    }

    private function psychologist(User $user, array $p, int $index): Psychologist
    {
        [$first, $last, $gender, $birthYear, $experience, $price, $pricePair, $approaches, $specs, $headline] = $p;
        $psy = Psychologist::withTrashed()->firstOrNew(['user_id' => $user->id]);
        $psy->forceFill([
            'slug' => $psy->slug ?? Psychologist::uniqueSlug($first.' '.$last),
            'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'birth_year' => $birthYear,
            'experience_years' => $experience, 'headline' => $headline,
            'about' => $headline."\n\nВ работе опираюсь на доказательные подходы и регулярно прохожу супервизию. Сессии провожу бережно и в комфортном темпе.",
            'education' => [['institution' => 'МГУ им. М. В. Ломоносова', 'specialty' => 'Психология', 'year' => $birthYear + 23]],
            'works_individual' => true, 'works_pair' => $pricePair !== null,
            'price_individual' => $price, 'price_pair' => $pricePair, 'price_category_id' => PriceCategory::forPrice($price)?->id,
            'qualification_status' => 'approved', 'qualified_at' => now()->subMonths(3), 'activity_status' => 'active_met',
            'work_status' => 'active', 'is_published' => true, 'published_at' => now()->subMonths(3), 'timezone' => 'Europe/Moscow',
        ])->save();

        $approachIds = Approach::whereIn('slug', $approaches)->pluck('id');
        $psy->approaches()->sync($approachIds->mapWithKeys(fn ($id) => [$id => ['explanation' => 'Использую в работе с большинством запросов.']])->all());
        $psy->specializations()->sync(Specialization::whereIn('slug', $specs)->pluck('id'));
        $requests = ClientRequest::where('format', 'individual')->inRandomOrder($index + 1)->limit(10)->pluck('id');
        if ($pricePair) {
            $requests = $requests->merge(ClientRequest::where('format', 'pair')->pluck('id'));
        }
        $psy->requests()->sync($requests);

        if (! $psy->scheduleIntervals()->exists()) {
            $weekdays = $index % 2 === 0 ? [1, 2, 3, 4, 5] : [2, 3, 4, 5, 6];
            foreach ($weekdays as $day) {
                ScheduleInterval::create(['psychologist_id' => $psy->id, 'weekday' => $day, 'starts_at' => '10:00', 'ends_at' => '14:00']);
                ScheduleInterval::create(['psychologist_id' => $psy->id, 'weekday' => $day, 'starts_at' => '16:00', 'ends_at' => '21:00']);
            }
        }

        return $psy;
    }
}
