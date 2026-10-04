<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Services\SearchIndex;
use App\Modules\Dictionaries\Models\ClientRequest;
use App\Modules\Psychologists\Models\Psychologist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsPsychologistProfiles;
use Tests\Concerns\CreatesPsychologists;
use Tests\TestCase;

/** CATALOG and SEARCH (SITE-02, SITE-03). */
class CatalogTest extends TestCase
{
    use BuildsPsychologistProfiles, CreatesPsychologists;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2026-10-05 08:00 MSK.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 05:00:00', 'UTC'));
        $this->fakeDisks();
        Cache::flush();
    }

    /** @return list<string> */
    private function ids(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'id');
    }

    public function test_only_bookable_profiles_are_listed_and_pages_have_two_variants(): void
    {
        $active = $this->completePsychologist();
        $paused = $this->completePsychologist(['work_status' => 'paused']);
        $blocked = $this->completePsychologist(['work_status' => 'blocked']);
        $inactive = $this->completePsychologist(['activity_status' => 'inactive']);
        $unpublished = $this->completePsychologist(['is_published' => false]);
        $draft = $this->draftPsychologist();
        $inReview = $this->draftPsychologist();
        $inReview->forceFill(['qualification_status' => 'in_review'])->save();
        $rejected = $this->draftPsychologist();
        $rejected->forceFill(['qualification_status' => 'rejected'])->save();

        $this->assertSame([$active->id], $this->ids('/api/v1/psychologists'));

        $this->getJson("/api/v1/psychologists/{$active->slug}")->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.approaches.0.explanation', 'Применяю в работе с большинством запросов.')
            ->assertJsonPath('data.approaches.0.description', fn ($d) => is_string($d) && $d !== '')
            ->assertJsonPath('data.session_durations.individual', 50);

        foreach ([$paused, $blocked, $inactive, $unpublished] as $p) {
            $this->getJson("/api/v1/psychologists/{$p->slug}")->assertOk()
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath('data.name', $p->fullName())
                ->assertJsonMissingPath('data.price_individual')
                ->assertJsonMissingPath('data.nearest_slot');
        }
        foreach ([$draft, $inReview, $rejected] as $p) {
            $this->getJson("/api/v1/psychologists/{$p->slug}")->assertNotFound();
        }
        $this->getJson('/api/v1/psychologists/no-such-person')->assertNotFound();
    }

    public function test_filters(): void
    {
        $a = $this->completePsychologist(['gender' => 'female', 'birth_year' => 1985, 'price_individual' => 300000, 'experience_years' => 5],
            relations: ['approaches' => ['kpt'], 'requests' => ['trevoga']]);
        $b = $this->completePsychologist(['gender' => 'male', 'birth_year' => 1975, 'price_individual' => 600000, 'price_pair' => 800000, 'experience_years' => 20],
            relations: ['approaches' => ['geshtalt'], 'requests' => ['vygoranie'], 'pair_requests' => ['izmena']]);
        $c = $this->completePsychologist(['gender' => 'female', 'birth_year' => 1995, 'price_individual' => 450000, 'experience_years' => 10],
            relations: ['approaches' => ['act', 'kpt'], 'requests' => ['trevoga', 'vygoranie']]);

        $same = fn (array $expected, string $query) => $this->assertEqualsCanonicalizing(
            array_map(fn ($p) => $p->id, $expected), $this->ids('/api/v1/psychologists?'.$query), $query);

        $same([$a, $c], 'requests[]=trevoga');
        $same([$c], 'requests[]=trevoga&requests[]=vygoranie');
        $same([$a, $c], 'requests[]='.ClientRequest::where('slug', 'trevoga')->value('id'));
        $same([$b], 'requests[]=izmena');
        $same([$a, $c], 'approaches[]=kpt');
        $same([$b, $c], 'approaches[]=geshtalt&approaches[]=act');
        $same([$a, $b, $c], 'specializations[]=trevoga');
        $same([$b], 'gender=male');
        $same([$a, $b], 'age_min=40');
        $same([$a, $c], 'age_max=45');
        $same([$a], 'age_min=40&age_max=45');
        $same([$b], 'price_category=premium');
        $same([$a, $c], 'price_category[]=economy&price_category[]=standard');
        $same([$b], 'format=pair');
        $same([$a, $b, $c], 'format=individual');
        $same([], 'requests[]=no-such-request');

        $this->getJson('/api/v1/psychologists?gender=other')->assertUnprocessable();
        $this->getJson('/api/v1/psychologists?age_min=50&age_max=30')->assertUnprocessable();

        $this->assertSame([$a->id, $c->id, $b->id], $this->ids('/api/v1/psychologists?sort=price_asc'));
        $this->assertSame([$b->id, $c->id, $a->id], $this->ids('/api/v1/psychologists?sort=price_desc'));
        $this->assertSame([$b->id, $c->id, $a->id], $this->ids('/api/v1/psychologists?sort=experience'));

        $page = $this->getJson('/api/v1/psychologists?sort=price_asc&per_page=2&page=2')->assertOk();
        $page->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $b->id);

        $card = $this->getJson('/api/v1/psychologists?format=pair')->json('data.0');
        $this->assertSame(800000, $card['price_pair']);
        $this->assertSame('pair', $card['nearest_slot_format']);
        $this->assertSame('premium', $card['price_category']['code']);
        $this->assertArrayNotHasKey('rating', $card);
    }

    public function test_nearest_slot_sorting_and_availability_filter(): void
    {
        // A: Tuesday only; B: Monday afternoon; C: Sunday only.
        $a = $this->completePsychologist(intervals: [[2, '10:00', '14:00']]);
        $b = $this->completePsychologist(intervals: [[1, '15:00', '20:00']]);
        $c = $this->completePsychologist(intervals: [[7, '10:00', '14:00']]);
        $none = $this->completePsychologist(intervals: []);

        $this->assertSame([$b->id, $a->id, $c->id, $none->id], $this->ids('/api/v1/psychologists'));
        $this->getJson('/api/v1/psychologists')->assertJsonPath('data.0.nearest_slot', '2026-10-05T12:00:00Z')
            ->assertJsonPath('data.3.nearest_slot', null);
        $this->assertSame([$b->id, $a->id], $this->ids('/api/v1/psychologists?available_within_days=2'));
        $this->assertSame([$b->id], $this->ids('/api/v1/psychologists?available_within_days=1'));
    }

    public function test_full_text_search_in_russian(): void
    {
        $p1 = $this->completePsychologist(['headline' => 'Работаю с тревожностью и паническими атаками'], relations: ['specializations' => ['vygoranie'], 'requests' => ['stress']]);
        $p2 = $this->completePsychologist(['headline' => 'Помогаю парам пережить кризис'], relations: ['approaches' => ['geshtalt'], 'specializations' => ['para'], 'requests' => ['vygoranie']]);
        $p3 = $this->completePsychologist(['first_name' => 'Светлана', 'last_name' => 'Иванова'], relations: ['specializations' => ['rpp'], 'requests' => ['vygoranie']]);

        $this->assertSame([$p1->id], $this->ids('/api/v1/psychologists?q='.urlencode('тревожность')));
        $this->assertSame([$p1->id], $this->ids('/api/v1/psychologists?q='.urlencode('панических атак')));
        $this->assertSame([$p1->id], $this->ids('/api/v1/psychologists?q='.urlencode('трев')));
        $this->assertSame([$p2->id], $this->ids('/api/v1/psychologists?q='.urlencode('гештальт')));
        $this->assertSame([$p2->id], $this->ids('/api/v1/psychologists?q='.urlencode('пары')));
        $this->assertSame([$p3->id], $this->ids('/api/v1/psychologists?q='.urlencode('Светлана')));
        $this->assertSame([], $this->ids('/api/v1/psychologists?q=zzzqqq'));
        $this->assertSame([], $this->ids('/api/v1/psychologists?q='.urlencode('!!!')));
        $this->assertCount(1, $this->ids('/api/v1/psychologists?sort=relevance&q='.urlencode('кризис')));
    }

    public function test_pending_changes_are_not_searchable_until_approved(): void
    {
        $p = $this->completePsychologist();
        Sanctum::actingAs($p->user);
        $this->patchJson('/api/v1/pro/profile', ['headline' => 'Специализируюсь на нейрографике и арт-терапии'])->assertOk();
        $this->assertSame([], $this->ids('/api/v1/psychologists?q='.urlencode('нейрографика')));

        $this->actingAsRole('admin');
        $this->postJson("/api/v1/admin/psychologists/{$p->id}/changes/approve")->assertOk();
        $this->assertSame([$p->id], $this->ids('/api/v1/psychologists?q='.urlencode('нейрографика')));
    }

    public function test_reindex_command_rebuilds_vectors_and_price_categories(): void
    {
        $p = $this->completePsychologist(['price_individual' => 360000]);
        DB::table('psychologists')->where('id', $p->id)->update(['search_vector' => null, 'price_category_id' => null]);
        $this->assertSame([], $this->ids('/api/v1/psychologists?q='.urlencode('бережно')));

        $this->artisan('catalog:reindex')->assertSuccessful();
        $this->assertSame([$p->id], $this->ids('/api/v1/psychologists?q='.urlencode('бережно')));
        $this->assertSame('standard', Psychologist::find($p->id)->priceCategory->code);
        $this->assertSame('тревожн:* & сост:*', SearchIndex::prefixQuery('Тревожн, сост!'));
        $this->assertNull(SearchIndex::prefixQuery('! ?'));
    }

    public function test_slots_endpoint_returns_utc_slots_by_format(): void
    {
        $p = $this->completePsychologist(['price_pair' => 700000], [[2, '10:00', '14:00']]);
        $q = '?from='.urlencode('2026-10-06T00:00:00+03:00').'&to='.urlencode('2026-10-06T23:59:00+03:00');

        $this->getJson("/api/v1/psychologists/{$p->slug}/slots{$q}")->assertOk()
            ->assertJsonPath('data.format', 'individual')
            ->assertJsonPath('data.duration_min', 50)
            ->assertJsonPath('data.slots', ['2026-10-06T07:00:00Z', '2026-10-06T08:00:00Z', '2026-10-06T09:00:00Z', '2026-10-06T10:00:00Z']);
        $this->getJson("/api/v1/psychologists/{$p->slug}/slots{$q}&format=pair")->assertOk()
            ->assertJsonPath('data.duration_min', 90)
            ->assertJsonPath('data.slots', ['2026-10-06T07:00:00Z', '2026-10-06T08:40:00Z']);

        $single = $this->completePsychologist(intervals: [[2, '10:00', '14:00']]);
        $this->getJson("/api/v1/psychologists/{$single->slug}/slots{$q}&format=pair")->assertJsonPath('data.slots', []);

        $paused = $this->completePsychologist(['work_status' => 'paused'], [[2, '10:00', '14:00']]);
        $this->getJson("/api/v1/psychologists/{$paused->slug}/slots{$q}")->assertOk()
            ->assertJsonPath('data.is_active', false)->assertJsonPath('data.slots', []);

        $this->getJson('/api/v1/psychologists/'.$this->draftPsychologist()->slug.'/slots')->assertNotFound();
        $this->getJson("/api/v1/psychologists/{$p->slug}/slots?format=group")->assertUnprocessable();
    }

    public function test_view_counter(): void
    {
        $p = $this->completePsychologist();
        $this->postJson("/api/v1/psychologists/{$p->slug}/views")->assertOk();
        $this->postJson("/api/v1/psychologists/{$p->slug}/views")->assertOk();
        $this->assertSame(2, (int) $p->fresh()->views_count);
        $this->postJson('/api/v1/psychologists/nobody/views')->assertNotFound();
    }
}
