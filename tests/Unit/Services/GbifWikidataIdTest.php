<?php

namespace Tests\Unit\Services;

use App\Services\GbifService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GbifWikidataIdTest extends TestCase
{
    public function test_all_known_spellings_of_a_long_qid_are_asked_for(): void
    {
        $v = (new GbifService())->wikidataIdentifierVariants('Q138465315');

        $this->assertSame([
            'http://www.wikidata.org/entity/Q138465315',
            'https://www.wikidata.org/entity/Q138465315',
            'http://www.wikidata.org/wiki/Q138465315',
            'https://www.wikidata.org/wiki/Q138465315',
            'Q138465315', // some publishers write only the QID
        ], $v);
    }

    public function test_short_qids_are_asked_for_bare_too(): void
    {
        // Old, famous expeditions have short QIDs (Amundsen's South Pole expedition is Q8414)
        $this->assertContains('Q8414', (new GbifService())->wikidataIdentifierVariants('Q8414'));
    }

    public function test_a_match_through_a_uri_is_always_accepted(): void
    {
        $gbif = new GbifService();

        // even a 2015 record: a URI is unambiguous
        $this->assertTrue($gbif->isPlausibleMatch(
            ['parentEventID' => 'http://www.wikidata.org/entity/Q8414', 'eventDate' => '2015'], 'parentEventID', 'Q8414', 1910, 1912));
        $this->assertTrue($gbif->isPlausibleMatch(
            ['parentEventID' => 'https://www.wikidata.org/wiki/Q8414'], 'parentEventID', 'Q8414', 1910, 1912));
    }

    public function test_a_bare_long_qid_is_always_accepted_whatever_its_date(): void
    {
        $this->assertTrue((new GbifService())->isPlausibleMatch(
            ['parentEventID' => 'Q138465315', 'eventDate' => '1999'], 'parentEventID', 'Q138465315', 1905, 1905));
    }

    public function test_a_bare_short_qid_needs_a_date_inside_the_expedition(): void
    {
        $gbif = new GbifService();
        $check = fn($raw) => $gbif->isPlausibleMatch($raw + ['eventID' => 'Q8414'], 'eventID', 'Q8414', 1910, 1912);

        $this->assertTrue($check(['eventDate' => '1911-05-04']));
        $this->assertTrue($check(['eventDate' => '1910-06-03/1910-06-10']), 'ranges use their start');
        $this->assertTrue($check(['eventDate' => '1909']), 'one year of slack before');
        $this->assertTrue($check(['eventDate' => '1913']), 'one year of slack after');
        $this->assertTrue($check(['year' => 1911]), 'falls back to the year field');

        $this->assertFalse($check(['eventDate' => '2015']), 'a local code from an unrelated dataset');
        $this->assertFalse($check(['eventDate' => '1905']));
        $this->assertFalse($check([]), 'no date: nothing to verify it with');
    }

    public function test_a_bare_short_qid_cannot_be_verified_without_expedition_dates(): void
    {
        $this->assertFalse((new GbifService())->isPlausibleMatch(
            ['eventID' => 'Q8414', 'eventDate' => '1911'], 'eventID', 'Q8414', null, null));
    }

    public function test_an_expedition_with_only_a_start_year_uses_it_for_both_ends(): void
    {
        $gbif = new GbifService();

        $this->assertTrue($gbif->isPlausibleMatch(['eventID' => 'Q8414', 'eventDate' => '1911'], 'eventID', 'Q8414', 1910, null));
        $this->assertFalse($gbif->isPlausibleMatch(['eventID' => 'Q8414', 'eventDate' => '1915'], 'eventID', 'Q8414', 1910, null));
    }

    public function test_year_of_handles_the_date_shapes_gbif_and_wikidata_use(): void
    {
        $gbif = new GbifService();

        $this->assertSame(1905, $gbif->yearOf('1905'));
        $this->assertSame(1905, $gbif->yearOf('1905-03'));
        $this->assertSame(1905, $gbif->yearOf('1905-03-04'));
        $this->assertSame(1905, $gbif->yearOf('1905-03-04/1905-04-01'));
        $this->assertNull($gbif->yearOf(null));
        $this->assertNull($gbif->yearOf(''));
        $this->assertNull($gbif->yearOf('unknown'));
    }

    public function test_one_query_per_field_repeats_the_filter_for_every_spelling(): void
    {
        Http::fake(['api.gbif.org/*' => Http::response(['results' => [], 'endOfRecords' => true], 200)]);

        (new GbifService())->searchByWikidataId('Q138465315', 'parentEventID', 200, 100);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $url = $request->url();
            // GBIF ORs repeated values of one filter, so every spelling goes in the same request
            return substr_count($url, 'parentEventID=') === 5
                && str_contains($url, 'parentEventID=Q138465315')
                && str_contains($url, 'parentEventID=http%3A%2F%2Fwww.wikidata.org%2Fentity%2FQ138465315')
                && str_contains($url, 'parentEventID=https%3A%2F%2Fwww.wikidata.org%2Fwiki%2FQ138465315')
                && str_contains($url, 'offset=200') && str_contains($url, 'limit=100');
        });
    }

    public function test_returns_the_page_and_whether_it_was_the_last(): void
    {
        Http::fake(['api.gbif.org/*' => Http::response([
            'results' => [['key' => 1], ['key' => 2]], 'endOfRecords' => true,
        ], 200)]);

        $page = (new GbifService())->searchByWikidataId('Q138465315', 'eventID');

        $this->assertCount(2, $page['results']);
        $this->assertTrue($page['endOfRecords']);
    }

    public function test_large_gbif_keys_survive_as_strings(): void
    {
        Http::fake(['api.gbif.org/*' => Http::response('{"results":[{"key":5598813683}],"endOfRecords":true}', 200)]);

        $page = (new GbifService())->searchByWikidataId('Q138465315', 'eventID');

        $this->assertSame('5598813683', (string) $page['results'][0]['key']);
    }

    public function test_an_error_is_null_and_not_an_empty_result(): void
    {
        Http::fake(['api.gbif.org/*' => Http::response('boom', 503)]);
        $this->assertNull((new GbifService())->searchByWikidataId('Q138465315', 'eventID'));

        Http::fake(['api.gbif.org/*' => fn() => throw new ConnectionException('cURL error 7')]);
        $this->assertNull((new GbifService())->searchByWikidataId('Q138465315', 'eventID'));
    }
}
