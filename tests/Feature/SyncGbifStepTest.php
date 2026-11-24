<?php

namespace Tests\Feature;

use App\Http\Controllers\SyncController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The GBIF discovery step must (a) find occurrences however the publisher wrote
 * the expedition's Wikidata id and (b) work through them a page at a time, across
 * requests, because one expedition can have thousands.
 */
class SyncGbifStepTest extends TestCase
{
    private const QID = 'Q138465315';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sync.token' => null]);   // the developer's .env may define one

        Schema::create('expeditions', function ($t) {
            $t->increments('id');
            $t->string('wikidata_qid')->nullable();
            $t->string('label')->nullable();
            $t->string('start_time')->nullable();
            $t->string('end_time')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            $t->timestamps();
        });
        Schema::create('specimens', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('person_id')->nullable();
            $t->string('gbif_id', 20)->nullable()->unique();
            foreach (['dataset_key', 'occurrence_id', 'action', 'basis_of_record', 'license', 'scientific_name', 'family',
                'kingdom', 'type_status', 'recorded_by', 'recorded_by_id', 'identified_by', 'identified_by_id',
                'institution_code', 'collection_code', 'catalog_number', 'date_identified', 'event_date', 'year', 'country',
                'country_code', 'coord_source', 'coord_status', 'image', 'gbif_url', 'bionomia_created_by',
                'bionomia_created_by_uri', 'bionomia_created', 'locality', 'verbatim_locality', 'higher_geography',
                'state_province', 'county', 'municipality'] as $c) {
                $t->text($c)->nullable();
            }
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lon', 10, 7)->nullable();
            $t->unsignedInteger('coord_uncertainty_m')->nullable();
            $t->timestamp('gbif_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('expedition_occurrences', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('expedition_id');
            $t->unsignedInteger('specimen_id');
            $t->unsignedInteger('person_id')->nullable();
            $t->unique(['expedition_id', 'specimen_id']);
        });
        Schema::create('sync_state', function ($t) {
            $t->string('key', 64)->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        DB::table('expeditions')->insert(['id' => 7, 'wikidata_qid' => self::QID, 'label' => 'Test expedition']);
    }

    private function occ(int $key): array
    {
        return ['key' => $key, 'datasetKey' => 'ds', 'scientificName' => "Species {$key}", 'eventDate' => '1881-08-05',
            'decimalLatitude' => 40.1, 'decimalLongitude' => -7.5, 'recordedBy' => 'Someone'];
    }

    /** A fake GBIF whose pages follow the request's offset/limit, like the real one. */
    private function fakeGbif(int $total, ?int $failOnCall = null): void
    {
        $calls = 0;
        Http::fake(['api.gbif.org/v1/occurrence/search*' => function ($request) use ($total, $failOnCall, &$calls) {
            $calls++;
            if ($failOnCall !== null && $calls === $failOnCall) return Http::response('boom', 503);

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $q);   // only the last of repeated keys is kept, enough here
            $field  = str_contains($request->url(), 'parentEventID=') ? 'parentEventID' : 'eventID';
            $offset = (int) $q['offset']; $limit = (int) $q['limit'];

            // everything lives under parentEventID; eventID has nothing
            $n = $field === 'parentEventID' ? $total : 0;
            $results = [];
            for ($i = $offset; $i < min($offset + $limit, $n); $i++) $results[] = $this->occ(1000 + $i);

            return Http::response(['count' => $n, 'results' => $results, 'endOfRecords' => $offset + $limit >= $n], 200);
        }]);
    }

    private function step(int $budget = 0): array
    {
        $controller = app(SyncController::class);
        $controller->gbifBudgetSeconds = $budget;   // 0 = stop after every page, to exercise resuming
        $response = $controller->syncExpeditionGbif(Request::create('/', 'GET'), 7);

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    public function test_works_through_all_pages_across_requests_and_then_moves_on(): void
    {
        $this->fakeGbif(250); // 3 pages of 100, 100, 50

        $requests = 0;
        do {
            [$status, $json] = $this->step(0);
            $requests++;
            $this->assertSame(200, $status);
        } while (str_contains($json['next'], '/gbif') && $requests < 20);

        $this->assertStringContainsString('/georeference', $json['next']);
        $this->assertSame(250, DB::table('specimens')->count());
        $this->assertSame(250, DB::table('expedition_occurrences')->where('expedition_id', 7)->count());
        $this->assertNull(DB::table('sync_state')->where('key', 'gbif_discovery_cursor_7')->value('value'), 'cursor cleared when done');
        $this->assertGreaterThan(1, $requests, 'one page per request, not everything at once');
    }

    public function test_each_request_saves_only_what_fits_in_its_budget(): void
    {
        $this->fakeGbif(250);

        [, $json] = $this->step(0);

        $this->assertSame(100, $json['gbif_count']);
        $this->assertSame(100, DB::table('specimens')->count());
        $this->assertStringContainsString('/gbif', $json['next'], 'calls itself again');
        $this->assertSame('0:100', DB::table('sync_state')->where('key', 'gbif_discovery_cursor_7')->value('value'));
    }

    public function test_a_gbif_failure_keeps_the_position_and_does_not_finish_the_expedition(): void
    {
        $this->fakeGbif(250, failOnCall: 2);

        $this->step(0);                       // page 1 saved
        [$status, $json] = $this->step(0);    // page 2: GBIF errors

        $this->assertSame(503, $status);
        $this->assertSame('error', $json['status']);
        $this->assertSame('0:100', DB::table('sync_state')->where('key', 'gbif_discovery_cursor_7')->value('value'), 'resumes where it stopped');

        [$status, $json] = $this->step(0);    // retry: continues with page 2
        $this->assertSame(200, $status);
        $this->assertSame(200, DB::table('specimens')->count());
    }

    public function test_specimens_listed_explicitly_on_wikidata_are_resumable_too(): void
    {
        Http::fake([
            'api.gbif.org/v1/occurrence/search*' => Http::response(['results' => [], 'endOfRecords' => true], 200),
            'api.gbif.org/v1/occurrence/*'       => fn($r) => Http::response($this->occ((int) basename($r->url()))),
        ]);
        DB::table('sync_state')->insert(['key' => 'gbif_ids_7', 'value' => json_encode(['501', '502', '503'])]);

        [, $json] = $this->step(0);   // one id per request when the budget is 0
        $this->assertStringContainsString('/gbif', $json['next']);
        $this->assertSame(['502', '503'], json_decode(DB::table('sync_state')->where('key', 'gbif_ids_7')->value('value')));

        do { [, $json] = $this->step(0); } while (str_contains($json['next'], '/gbif'));
        $this->assertSame(3, DB::table('specimens')->count());
        $this->assertNull(DB::table('sync_state')->where('key', 'gbif_ids_7')->value('value'));
    }

    public function test_a_short_qid_only_collects_records_that_fit_the_expedition_dates(): void
    {
        // Amundsen's South Pole expedition, 1910-1912. GBIF has unrelated records whose own
        // event code happens to be "Q8414", and the URI form is always unambiguous.
        DB::table('expeditions')->where('id', 7)->update(['wikidata_qid' => 'Q8414', 'start_time' => '1910-06-03', 'end_time' => '1912-01-30']);

        $rows = [
            ['key' => 1, 'parentEventID' => 'Q8414', 'eventDate' => '1911-05-04'],                          // genuine, bare QID
            ['key' => 2, 'parentEventID' => 'Q8414', 'eventDate' => '2015'],                                // someone's local code
            ['key' => 3, 'parentEventID' => 'Q8414'],                                                       // local code, no date
            ['key' => 4, 'parentEventID' => 'http://www.wikidata.org/entity/Q8414', 'eventDate' => '2015'], // URI: always accepted
        ];
        Http::fake(['api.gbif.org/v1/occurrence/search*' => function ($request) use ($rows) {
            $isParent = str_contains($request->url(), 'parentEventID=');
            return Http::response(['count' => count($rows), 'results' => $isParent ? array_map(fn($r) => $r + $this->occ($r['key']), $rows) : [], 'endOfRecords' => true], 200);
        }]);

        do { [, $json] = $this->step(0); } while (str_contains($json['next'], '/gbif'));

        $this->assertEqualsCanonicalizing(['1', '4'], DB::table('specimens')->pluck('gbif_id')->all());
    }

    public function test_an_expedition_with_nothing_in_gbif_just_moves_on(): void
    {
        $this->fakeGbif(0);

        [$status, $json] = $this->step(40);

        $this->assertSame(200, $status);
        $this->assertSame(0, $json['gbif_count']);
        $this->assertStringContainsString('/georeference', $json['next']);
    }
}
