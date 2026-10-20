<?php

namespace Tests\Unit\Services;

use App\Services\WikidataService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WikidataPlaceBatchTest extends TestCase
{
    private function entity(string $qid, string $name, ?float $lat = 1.5, ?float $lon = 2.5): array
    {
        $e = [
            'id'           => $qid,
            'labels'       => ['en' => ['value' => $name]],
            'descriptions' => ['en' => ['value' => "About $name"]],
            'claims'       => [],
        ];
        if ($lat !== null) {
            $e['claims']['P625'][0]['mainsnak']['datavalue']['value'] = ['latitude' => $lat, 'longitude' => $lon];
        }
        return $e;
    }

    public function test_many_places_are_one_request_and_duplicates_collapse(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => Http::response(['entities' => [
            'Q1' => $this->entity('Q1', 'Falmouth'),
            'Q2' => $this->entity('Q2', 'Cape Town', -33.9, 18.4),
        ]], 200)]);

        // Falmouth is both start and end of the voyage
        $places = (new WikidataService())->getPlaceDetailsMany(['Q1', 'Q2', 'Q1']);

        Http::assertSentCount(1);
        Http::assertSent(fn($r) => $r['ids'] === 'Q1|Q2' && $r['action'] === 'wbgetentities');
        $this->assertSame('Falmouth', $places['Q1']['name']);
        $this->assertSame('About Cape Town', $places['Q2']['description']);
        $this->assertEquals(-33.9, $places['Q2']['lat']);
    }

    public function test_more_than_fifty_places_are_chunked(): void
    {
        $qids = array_map(fn($i) => "Q$i", range(1, 120));
        Http::fake(['wikidata.org/w/api.php*' => fn($r) => Http::response(['entities' => collect(explode('|', $r['ids']))
            ->mapWithKeys(fn($q) => [$q => $this->entity($q, "Place $q")])->all()], 200)]);

        $places = (new WikidataService())->getPlaceDetailsMany($qids);

        Http::assertSentCount(3); // 50 + 50 + 20
        $this->assertCount(120, $places);
        $this->assertSame('Place Q120', $places['Q120']['name']);
    }

    public function test_edits_on_wikidata_show_up_on_the_next_sync(): void
    {
        // The sync exists so Wikidata edits propagate immediately — nothing may
        // be served from a previous run.
        Http::fake(['wikidata.org/w/api.php*' => Http::sequence()
            ->push(['entities' => ['Q1' => $this->entity('Q1', 'Falmouth', 50.1, -5.0)]], 200)
            ->push(['entities' => ['Q1' => $this->entity('Q1', 'Falmouth (corrected)', 50.2, -5.1)]], 200)]);

        $service = new WikidataService();
        $first   = $service->getPlaceDetailsMany(['Q1']);
        $second  = $service->getPlaceDetailsMany(['Q1']);

        Http::assertSentCount(2);
        $this->assertSame('Falmouth', $first['Q1']['name']);
        $this->assertSame('Falmouth (corrected)', $second['Q1']['name']);
        $this->assertEquals(50.2, $second['Q1']['lat']);
    }

    public function test_missing_item_is_null(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => Http::response(['entities' => [
            'Q9' => ['id' => 'Q9', 'missing' => ''],
        ]], 200)]);

        $this->assertNull((new WikidataService())->getPlaceDetailsMany(['Q9'])['Q9']);
    }

    public function test_wikidata_failure_returns_null_not_empty(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => Http::response('boom', 500)]);

        $this->assertNull((new WikidataService())->getPlaceDetailsMany(['Q1']));
    }

    public function test_connection_failure_returns_null(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('down')]);

        $this->assertNull((new WikidataService())->getPlaceDetailsMany(['Q1']));
    }

    public function test_get_entities_chunks_passes_extra_params_and_skips_missing(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => fn($r) => Http::response(['entities' => collect(explode('|', $r['ids']))
            ->mapWithKeys(fn($q) => [$q => $q === 'Q3' ? ['id' => $q, 'missing' => ''] : $this->entity($q, "Person $q")])->all()], 200)]);

        $entities = (new WikidataService())->getEntities(['Q1', 'Q2', 'Q3', 'Q4', 'Q5'], 'labels|aliases|sitelinks', chunk: 2, extra: ['sitefilter' => 'enwiki']);

        Http::assertSentCount(3); // 2 + 2 + 1
        Http::assertSent(fn($r) => $r['sitefilter'] === 'enwiki' && $r['props'] === 'labels|aliases|sitelinks');
        $this->assertSame(['Q1', 'Q2', 'Q4', 'Q5'], array_keys($entities)); // Q3 missing → left out
    }

    public function test_get_entities_failure_returns_null(): void
    {
        Http::fake(['wikidata.org/w/api.php*' => Http::response('', 503)]);

        $this->assertNull((new WikidataService())->getEntities(['Q1'], 'labels'));
    }
}
