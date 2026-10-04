<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Catalog\Services\SearchIndex;
use App\Modules\Dictionaries\Models\Approach;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Dictionaries\Models\PriceCategory;
use App\Modules\Dictionaries\Models\Specialization;
use App\Modules\Files\Models\StoredFile;
use App\Modules\Psychologists\Models\Psychologist;
use App\Modules\Psychologists\Models\QualificationDocument;
use App\Modules\Rbac\Services\RbacService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stream A demo: verified documents of the demo psychologists (shown by title on SITE-03), a candidate whose
 * application waits in ADM-03 (candidate@teta.local), price history and the full-text search index.
 */
class PsychologistsDemoSeeder extends Seeder
{
    public function run(): void
    {
        Psychologist::with('user')->where('qualification_status', 'approved')->get()->each(function (Psychologist $p) {
            if (! $p->documents()->exists()) {
                $year = ($p->birth_year ?? 1985) + 23;
                $this->document($p, 'diploma', 'Диплом о высшем образовании по специальности «Психология»', 'МГУ им. М. В. Ломоносова', 'Психология', $year, 'approved');
                $this->document($p, 'certificate', 'Сертификат о повышении квалификации', 'Московский институт психоанализа', null, min((int) now()->year, $year + 5), 'approved');
            }
            if (! DB::table('psychologist_price_history')->where('psychologist_id', $p->id)->exists()) {
                DB::table('psychologist_price_history')->insert([
                    'id' => (string) Str::uuid(), 'psychologist_id' => $p->id, 'price_individual' => $p->price_individual,
                    'price_pair' => $p->price_pair, 'price_category_id' => $p->price_category_id, 'created_at' => $p->published_at ?? now(),
                ]);
            }
        });

        $this->candidate();
        app(SearchIndex::class)->refreshAll();
    }

    private function candidate(): void
    {
        $user = User::withTrashed()->updateOrCreate(['email' => 'candidate@teta.local'], [
            'name' => 'Мария', 'last_name' => 'Кандидатова', 'password' => DemoSeeder::PASSWORD,
            'birth_date' => '1991-05-12', 'timezone' => 'Europe/Moscow',
        ]);
        $user->forceFill(['email_verified_at' => now(), 'status' => User::STATUS_ACTIVE])->save();
        app(RbacService::class)->assignRole($user, 'psychologist');

        $p = Psychologist::withTrashed()->firstOrNew(['user_id' => $user->id]);
        if ($p->exists) {
            return;
        }
        $price = 320000;
        $p->forceFill([
            'slug' => Psychologist::uniqueSlug('Мария Кандидатова'),
            'first_name' => 'Мария', 'last_name' => 'Кандидатова', 'gender' => 'female', 'birth_year' => 1991,
            'experience_years' => 4, 'headline' => 'Помогаю справляться с тревогой и неуверенностью в себе.',
            'about' => 'Работаю в когнитивно-поведенческом подходе. Помогаю разобраться в тревожных мыслях, восстановить опору и научиться бережнее относиться к себе. Сессии провожу в спокойном темпе.',
            'education' => [['institution' => 'РГГУ', 'specialty' => 'Клиническая психология', 'year' => 2014]],
            'works_individual' => true, 'works_pair' => false, 'price_individual' => $price,
            'price_category_id' => PriceCategory::forPrice($price)?->id, 'timezone' => 'Europe/Moscow',
            'qualification_status' => 'draft', 'work_status' => 'active', 'is_published' => false,
        ])->save();
        $p->recordInitialState($user->id, field: 'qualification_status', event: 'psy.qualification.draft');

        $p->approaches()->sync(Approach::whereIn('slug', ['kpt', 'act'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['explanation' => 'Помогает замечать тревожные мысли и пробовать новые способы действовать.']])->all());
        $p->specializations()->sync(Specialization::whereIn('slug', ['trevoga', 'samoocenka'])->pluck('id'));
        $p->requests()->sync(ClientRequest::where('format', 'individual')->whereIn('slug', ['trevoga', 'samoocenka', 'stress', 'panicheskie-ataki'])->pluck('id'));
        $this->document($p, 'diploma', 'Диплом специалиста «Клиническая психология»', 'РГГУ', 'Клиническая психология', 2014, 'pending');

        $p->transitionTo('in_review', $user->id, 'submitted', ['qualification_submitted_at' => now()->subDay()], field: 'qualification_status', event: 'psy.qualification.submitted');
    }

    private function document(Psychologist $p, string $kind, string $title, string $institution, ?string $specialty, int $year, string $status): void
    {
        $disk = config('filesystems.private_files_disk', 'local');
        $path = 'qualification/demo/'.Str::uuid().'.pdf';
        $content = "%PDF-1.4\n% Демо-документ: {$title}\n%%EOF\n";
        Storage::disk($disk)->put($path, $content);
        $file = StoredFile::create([
            'owner_id' => $p->user_id, 'disk' => $disk, 'path' => $path, 'original_name' => Str::slug(Str::ascii($title)).'.pdf',
            'mime_type' => 'application/pdf', 'size' => strlen($content), 'visibility' => 'private', 'purpose' => 'qualification',
            'checksum' => hash('sha256', $content), 'scan_status' => 'skipped',
        ]);
        QualificationDocument::create([
            'psychologist_id' => $p->id, 'file_id' => $file->id, 'kind' => $kind, 'title' => $title,
            'institution' => $institution, 'specialty' => $specialty, 'year' => $year, 'status' => $status,
            'reviewed_at' => $status === 'approved' ? now()->subMonths(3) : null,
        ]);
    }
}
