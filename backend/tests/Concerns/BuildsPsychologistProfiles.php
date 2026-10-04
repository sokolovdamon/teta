<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Modules\Catalog\Services\SearchIndex;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Psychologists\Models\Psychologist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stream A helpers on top of CreatesPsychologists: complete public profiles for catalog tests and draft profiles
 * for the qualification flow. Use together with CreatesPsychologists.
 */
trait BuildsPsychologistProfiles
{
    protected function fakeDisks(): void
    {
        Storage::fake('local');
        Storage::fake('public');
    }

    /**
     * Approved, published psychologist with every required field (BR-PSY-02) and indexed for search.
     *
     * @param  array{approaches?: list<string>, requests?: list<string>, pair_requests?: list<string>, specializations?: list<string>}  $relations  dictionary slugs
     */
    protected function completePsychologist(array $overrides = [], ?array $intervals = null, array $relations = []): Psychologist
    {
        $p = $this->makePsychologist([
            'birth_year' => 1985,
            'headline' => 'Помогаю бережно разобраться в себе',
            'about' => 'Работаю с взрослыми клиентами. В работе опираюсь на доказательные подходы, регулярно прохожу супервизию и личную терапию.',
            'education' => [['institution' => 'МГУ им. М. В. Ломоносова', 'specialty' => 'Психология', 'year' => 2008]],
            ...$overrides,
        ], $intervals);

        $p->approaches()->sync(Approach::whereIn('slug', $relations['approaches'] ?? ['kpt'])->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['explanation' => 'Применяю в работе с большинством запросов.']])->all());
        $p->specializations()->sync(Specialization::whereIn('slug', $relations['specializations'] ?? ['trevoga'])->pluck('id'));
        $p->requests()->sync(ClientRequest::where('format', 'individual')->whereIn('slug', $relations['requests'] ?? ['trevoga'])->pluck('id')
            ->merge(ClientRequest::where('format', 'pair')->whereIn('slug', $relations['pair_requests'] ?? [])->pluck('id')));
        app(SearchIndex::class)->refresh($p);

        return $p->fresh();
    }

    /** A freshly registered psychologist: draft qualification, empty profile, no documents. */
    protected function draftPsychologist(?User $user = null): Psychologist
    {
        $user ??= User::factory()->withRole('psychologist')->create();
        $p = new Psychologist([
            'user_id' => $user->id,
            'slug' => Psychologist::uniqueSlug($user->name.' '.$user->last_name),
            'first_name' => $user->name,
            'last_name' => $user->last_name,
            'timezone' => 'Europe/Moscow',
        ]);
        $p->forceFill(['qualification_status' => 'draft'])->save();

        return $p->fresh();
    }

    /** Body of PATCH /pro/profile that fills every required field. */
    protected function completeProfilePayload(): array
    {
        return [
            'gender' => 'female',
            'birth_year' => 1988,
            'headline' => 'Помогаю справляться с тревогой и выгоранием',
            'about' => 'Я практикующий психолог. Помогаю разобраться с тревогой, выгоранием и сложностями в отношениях. Работаю бережно, в комфортном для клиента темпе.',
            'experience_years' => 6,
            'education' => [['institution' => 'СПбГУ', 'specialty' => 'Психология', 'year' => 2012]],
            'approaches' => Approach::whereIn('slug', ['kpt', 'act'])->pluck('id')->map(fn ($id) => ['id' => $id, 'explanation' => 'Помогает замечать автоматические мысли.'])->all(),
            'specializations' => Specialization::whereIn('slug', ['trevoga'])->pluck('id')->all(),
            'requests' => ClientRequest::where('format', 'individual')->whereIn('slug', ['trevoga', 'vygoranie'])->pluck('id')->all(),
            'works_individual' => true,
            'price_individual' => 300000,
        ];
    }

    protected function pdf(string $name = 'diploma.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }
}
