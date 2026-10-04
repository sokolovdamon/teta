<?php

namespace Database\Seeders;

use App\Modules\Consent\Models\LegalDocument;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\RequestGroup;
use App\Modules\Dictionaries\Models\ServiceType;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Notifications\Models\NotificationTemplate;
use App\Modules\Notifications\NotificationCatalog;
use App\Modules\Rbac\Services\RbacService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Reference data every instance needs (idempotent): roles, dictionaries, documents, templates, calendar. */
class ReferenceDataSeeder extends Seeder
{
    /** Catalogue of 43 requests (decisions registry, section 6; DEC-11, DEC-49). */
    public const REQUESTS = [
        ['moe-sostoyanie', 'Моё состояние', 'individual', [
            ['Стресс', 'stress'], ['Упадок сил', 'upadok-sil'], ['Нестабильная самооценка', 'samoocenka'],
            ['Приступы страха и тревоги', 'trevoga'], ['Перепады настроения', 'perepady-nastroeniya'], ['Раздражительность', 'razdrazhitelnost'],
            ['Ощущение одиночества', 'odinochestvo'], ['СДВГ', 'sdvg'], ['Тяжёлое эмоциональное состояние', 'tyazheloe-sostoyanie'],
            ['Эмоциональная зависимость', 'emocionalnaya-zavisimost'], ['Панические атаки', 'panicheskie-ataki'], ['Навязчивые мысли о здоровье', 'mysli-o-zdorove'],
            ['Проблемы с концентрацией', 'koncentraciya'], ['Расстройство пищевого поведения', 'pishchevoe-povedenie'], ['Проблемы со сном', 'son'],
            ['Сложности с алкоголем и наркотиками', 'alkogol-narkotiki'],
        ]],
        ['otnosheniya', 'Отношения', 'individual', [
            ['С партнёром', 'otnosheniya-s-partnerom'], ['В целом, с окружающими', 'otnosheniya-s-okruzhayushchimi'], ['С родителями', 'otnosheniya-s-roditelyami'],
            ['С детьми', 'otnosheniya-s-detmi'], ['Сексуальные', 'seksualnye-otnosheniya'], ['Сложности с ориентацией, её поиск', 'orientaciya'],
            ['Не могу найти партнёра', 'ne-mogu-najti-partnera'],
        ]],
        ['rabota-ucheba', 'Работа, учёба', 'individual', [
            ['Недостаток мотивации', 'motivaciya'], ['Выгорание', 'vygoranie'], ['«Не знаю, чем хочу заниматься»', 'ne-znayu-chem-zanimatsya'],
            ['Прокрастинация', 'prokrastinaciya'], ['Отсутствие цели', 'net-celi'], ['Смена, потеря работы', 'poterya-raboty'],
        ]],
        ['sobytiya', 'События в жизни', 'individual', [
            ['Переезд, эмиграция', 'pereezd-emigraciya'], ['Беременность, рождение ребёнка', 'beremennost-rozhdenie-rebenka'], ['Разрыв отношений, развод', 'razryv-razvod'],
            ['Финансовые изменения', 'finansovye-izmeneniya'], ['Утрата близкого человека', 'utrata-blizkogo'], ['Болезнь, своя или близких', 'bolezn'], ['Насилие', 'nasilie'],
        ]],
        ['dlya-pary', 'Для пары', 'pair', [
            ['Рождение детей', 'rozhdenie-detej'], ['Измена', 'izmena'], ['Развод', 'razvod'], ['Сложности в отношениях', 'slozhnosti-v-otnosheniyah'],
            ['Созависимость', 'sozavisimost'], ['Сексуальные отношения', 'seksualnye-otnosheniya'], ['Детско-родительские отношения', 'detsko-roditelskie-otnosheniya', '18+'],
        ]],
    ];

    public const APPROACHES = [
        ['kpt', 'Когнитивно-поведенческая терапия (КПТ)', 'Помогает замечать автоматические мысли и менять привычные реакции; работа структурирована, с домашними заданиями.'],
        ['geshtalt', 'Гештальт-терапия', 'Фокус на том, что человек чувствует и делает «здесь и сейчас», на контакте с собой и другими.'],
        ['psihodinamicheskiy', 'Психодинамический подход', 'Исследует, как прошлый опыт и неосознаваемые чувства влияют на текущую жизнь и отношения.'],
        ['shema-terapiya', 'Схема-терапия', 'Работает с устойчивыми жизненными сценариями, которые сложились в детстве.'],
        ['act', 'Терапия принятия и ответственности (ACT)', 'Учит принимать трудные переживания и действовать в соответствии со своими ценностями.'],
        ['ekzistencialnyy', 'Экзистенциальный подход', 'Помогает искать смысл, принимать решения и обходиться со свободой, одиночеством и конечностью жизни.'],
        ['klient-centrirovannyy', 'Клиент-центрированная терапия', 'Безоценочное принятие и эмпатия помогают человеку самому найти ответы.'],
        ['sistemnaya-semeynaya', 'Системная семейная терапия', 'Рассматривает трудности как часть системы отношений в паре или семье.'],
        ['efs', 'Эмоционально-фокусированная терапия', 'Работает с эмоциями и привязанностью; часто используется в работе с парами.'],
        ['transaktnyy-analiz', 'Транзактный анализ', 'Помогает увидеть повторяющиеся способы общения и выбрать более подходящие.'],
        ['telesno-orientirovannaya', 'Телесно-ориентированная терапия', 'Связывает переживания с телесными ощущениями и помогает снижать напряжение.'],
        ['narrativnaya', 'Нарративная терапия', 'Помогает пересмотреть историю о себе и найти в ней опору.'],
    ];

    public const SPECIALIZATIONS = [
        ['trevoga', 'Тревожные состояния'], ['depressivnye', 'Сниженное настроение и апатия'], ['otnosheniya', 'Отношения и привязанность'],
        ['para', 'Работа с парами'], ['travma', 'Травматический опыт'], ['zavisimosti', 'Зависимости и созависимость'],
        ['krizisy', 'Жизненные кризисы и утраты'], ['samoocenka', 'Самооценка и уверенность'], ['vygoranie', 'Выгорание и работа'],
        ['rpp', 'Пищевое поведение'], ['roditelstvo', 'Родительство'], ['lgbt', 'Сексуальность и идентичность'],
    ];

    public function run(): void
    {
        app(RbacService::class)->syncCatalogue();

        foreach (self::REQUESTS as $gi => [$slug, $title, $format, $items]) {
            $group = RequestGroup::updateOrCreate(['slug' => $slug], ['title' => $title, 'format' => $format, 'sort' => $gi]);
            foreach ($items as $i => $item) {
                [$reqTitle, $reqSlug] = $item;
                ClientRequest::updateOrCreate(['slug' => $reqSlug, 'format' => $format], [
                    'request_group_id' => $group->id, 'title' => $reqTitle, 'age_label' => $item[2] ?? null,
                    'carousel_sort' => $gi * 100 + $i, 'seo_title' => $reqTitle.' — помощь психолога онлайн',
                    'seo_description' => "Психологи ТЕТА работают с запросом «{$reqTitle}». Только дипломированные специалисты, подбор и запись онлайн.",
                ]);
            }
        }

        foreach (self::APPROACHES as $i => [$slug, $title, $explanation]) {
            Approach::updateOrCreate(['slug' => $slug], ['title' => $title, 'explanation' => $explanation, 'sort' => $i]);
        }
        foreach (self::SPECIALIZATIONS as $i => [$slug, $title]) {
            Specialization::updateOrCreate(['slug' => $slug], ['title' => $title, 'sort' => $i]);
        }

        foreach ([
            ['individual', 'Индивидуальная сессия', 50], ['pair', 'Парная сессия', 90],
            ['supervision_individual', 'Индивидуальная супервизия', 60], ['supervision_group', 'Групповая супервизия', 90],
            ['intervision', 'Встреча интервизионной группы', 90], ['event', 'Мероприятие', 60],
        ] as [$code, $title, $duration]) {
            ServiceType::updateOrCreate(['code' => $code], ['title' => $title, 'duration_min' => $duration]);
        }

        // DEC-55: below 3 500 ₽; 3 500–5 499 ₽; 5 500 ₽ and above.
        foreach ([
            ['economy', 'До 3 500 ₽', 0, 349999, 0], ['standard', '3 500–5 500 ₽', 350000, 549999, 1], ['premium', 'От 5 500 ₽', 550000, null, 2],
        ] as [$code, $title, $min, $max, $sort]) {
            PriceCategory::updateOrCreate(['code' => $code], ['title' => $title, 'min_price' => $min, 'max_price' => $max, 'sort' => $sort]);
        }

        $this->documents();
        $this->templates();
        $this->calendar();

        // Module reference data: database/seeders/Reference/*Seeder.php are run automatically.
        foreach (self::discover('Reference') as $class) {
            $this->call($class);
        }
    }

    /** @return list<class-string<Seeder>> */
    public static function discover(string $folder): array
    {
        $classes = [];
        foreach (glob(database_path("seeders/{$folder}/*Seeder.php")) ?: [] as $file) {
            $classes[] = 'Database\\Seeders\\'.$folder.'\\'.basename($file, '.php');
        }
        sort($classes);

        return $classes;
    }

    /** Texts are provided by the customer (DEC-34); placeholders keep consent flows working until then. */
    private function documents(): void
    {
        $docs = [
            ['personal-data', 'Согласие на обработку персональных данных', LegalDocument::PERSONAL_DATA, true],
            ['terms', 'Пользовательское соглашение', LegalDocument::TERMS, true],
            ['offer', 'Публичная оферта', LegalDocument::OFFER, true],
            ['privacy', 'Политика обработки персональных данных', LegalDocument::PRIVACY_POLICY, false],
            ['review-consent', 'Согласие на публикацию отзыва', LegalDocument::REVIEW_PUBLICATION, true],
            ['mailing-consent', 'Согласие на получение рассылок', LegalDocument::MAILING, true],
            ['cookies', 'Политика использования cookies', LegalDocument::COOKIES, false],
            ['corporate-terms', 'Условия корпоративной программы', LegalDocument::CORPORATE_TERMS, true],
            ['psychologist-offer', 'Договор с психологом', 'psychologist_offer', true],
        ];
        foreach ($docs as [$slug, $title, $kind, $consent]) {
            $doc = LegalDocument::updateOrCreate(['slug' => $slug], ['title' => $title, 'kind' => $kind, 'requires_consent' => $consent, 'is_public' => true]);
            if (! $doc->versions()->exists()) {
                $doc->versions()->create([
                    'version' => '1.0',
                    'body' => "# {$title}\n\nТекст документа предоставляет заказчик (решение DEC-34). До публикации окончательной редакции действует эта версия-заглушка.\n\nРеквизиты: ИП Иващенко (DEC-50).",
                    'published_at' => now(),
                ]);
            }
        }
    }

    private function templates(): void
    {
        foreach (NotificationCatalog::all() as $code => $t) {
            NotificationTemplate::firstOrCreate(['code' => $code], [
                'title' => $t['title'] ?? $code,
                'audience' => $t['audience'] ?? 'any',
                'subject' => $t['subject'],
                'body' => $t['body'],
                'center_text' => $t['center_text'] ?? null,
                'send_email' => $t['send_email'] ?? true,
                'send_center' => $t['send_center'] ?? true,
                'is_transactional' => $t['is_transactional'] ?? true,
                'variables' => $t['variables'] ?? null,
            ]);
        }
    }

    /** Non-working days of the RF production calendar; admins correct it when the government decree changes. */
    private function calendar(): void
    {
        $days = [
            '2026-01-01', '2026-01-02', '2026-01-05', '2026-01-06', '2026-01-07', '2026-01-08', '2026-01-09',
            '2026-02-23', '2026-03-09', '2026-05-01', '2026-05-11', '2026-06-12', '2026-11-04', '2026-12-31',
            '2027-01-01', '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07', '2027-01-08',
            '2027-02-23', '2027-03-08', '2027-05-03', '2027-05-10', '2027-06-14', '2027-11-04',
        ];
        foreach ($days as $day) {
            DB::table('calendar_days')->updateOrInsert(['date' => $day], ['is_working' => false, 'title' => 'Нерабочий праздничный день']);
        }
    }
}
