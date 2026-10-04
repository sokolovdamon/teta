<?php

namespace App\Modules\Psychologists\Services;

use App\Modules\Psychologists\Models\Psychologist;

/**
 * BR-PSY-02: what must be filled before the qualification can be submitted, and BR-PSY-04: a profile is published
 * only when it is complete (plus an approved qualification and at least one working interval — PublicationService).
 * Works on the profile columns, i.e. on the published values of an approved psychologist and the current draft of
 * everybody else (their edits are applied directly).
 */
class ProfileCompleteness
{
    public const HEADLINE_MIN = 10;

    public const ABOUT_MIN = 100;

    public const LABELS = [
        'first_name' => 'Имя',
        'last_name' => 'Фамилия',
        'gender' => 'Пол',
        'birth_year' => 'Год рождения',
        'headline' => 'Коротко о себе',
        'about' => 'О себе',
        'experience_years' => 'Опыт работы',
        'education' => 'Образование',
        'approaches' => 'Подходы с пояснениями',
        'specializations' => 'Специализации',
        'requests' => 'Запросы',
        'photo_file_id' => 'Фото',
        'formats' => 'Форматы работы',
        'price_individual' => 'Стоимость индивидуальной сессии',
        'price_pair' => 'Стоимость парной сессии',
        'documents' => 'Документ о психологическом образовании',
    ];

    /** @return list<array{code: string, label: string, hint: string}> */
    public function missingProfile(Psychologist $p): array
    {
        $missing = [];
        $add = function (string $code, string $hint) use (&$missing) {
            $missing[] = ['code' => $code, 'label' => self::LABELS[$code], 'hint' => $hint];
        };

        if (trim((string) $p->first_name) === '') {
            $add('first_name', 'Укажите имя.');
        }
        if (trim((string) $p->last_name) === '') {
            $add('last_name', 'Укажите фамилию.');
        }
        if (! in_array($p->gender, ['female', 'male'], true)) {
            $add('gender', 'Укажите пол — клиенты фильтруют по нему каталог.');
        }
        if (! $p->birth_year) {
            $add('birth_year', 'Укажите год рождения — по нему показывается возраст.');
        }
        if (mb_strlen(trim((string) $p->headline)) < self::HEADLINE_MIN) {
            $add('headline', 'Одна-две фразы о том, с чем вы помогаете.');
        }
        if (mb_strlen(trim((string) $p->about)) < self::ABOUT_MIN) {
            $add('about', 'Расскажите о себе и о работе — не короче '.self::ABOUT_MIN.' символов.');
        }
        if ($p->experience_years === null) {
            $add('experience_years', 'Укажите опыт практики в годах.');
        }
        $education = collect((array) ($p->education ?? []))->filter(fn ($e) => trim((string) ($e['institution'] ?? '')) !== '');
        if ($education->isEmpty()) {
            $add('education', 'Добавьте хотя бы одно учебное заведение.');
        }
        if (! $p->approaches()->wherePivotNotNull('explanation')->exists()) {
            $add('approaches', 'Выберите хотя бы один подход и поясните, как применяете его в работе.');
        }
        if (! $p->requests()->exists()) {
            $add('requests', 'Выберите запросы, с которыми работаете.');
        }
        if (! $p->works_individual && ! $p->works_pair) {
            $add('formats', 'Выберите индивидуальный или парный формат.');
        }
        if ($p->works_individual && ! $p->price_individual) {
            $add('price_individual', 'Укажите цену индивидуальной сессии.');
        }
        if ($p->works_pair && ! $p->price_pair) {
            $add('price_pair', 'Укажите цену парной сессии.');
        }

        return $missing;
    }

    /** @return list<array{code: string, label: string, hint: string}> */
    public function missingForSubmission(Psychologist $p): array
    {
        $missing = $this->missingProfile($p);
        $hasEducationDocument = $p->documents()
            ->whereIn('kind', Psychologist::EDUCATION_DOCUMENT_KINDS)
            ->where('status', '!=', 'rejected')
            ->exists();
        if (! $hasEducationDocument) {
            $missing[] = [
                'code' => 'documents', 'label' => self::LABELS['documents'],
                'hint' => 'Загрузите диплом о психологическом образовании или о профессиональной переподготовке.',
            ];
        }

        return $missing;
    }

    public function isPublishable(Psychologist $p): bool
    {
        return $this->missingProfile($p) === [];
    }
}
