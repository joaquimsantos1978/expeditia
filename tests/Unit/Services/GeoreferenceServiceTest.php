<?php

namespace Tests\Unit\Services;

use App\Services\GeoreferenceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoreferenceServiceTest extends TestCase
{
    public function test_lookup_returns_normalised_validated_record(): void
    {
        Http::fake([
            'georeference.it/api/v1/occurrences/*' => Http::response([
                'occurrenceID' => '35284750',
                'decimalLatitude' => 41.9,
                'decimalLongitude' => 12.5,
                'georef_status' => 'validated',
            ], 200),
        ]);

        $service = new GeoreferenceService();
        $raw     = $service->lookupByGbifId('35284750');
        $data    = $service->normaliseOccurrence($raw);

        $this->assertSame('georeference', $data['coord_source']);
        $this->assertSame('validated', $data['coord_status']);
        $this->assertEqualsWithDelta(41.9, $data['lat'], 0.0001);
    }

    public function test_has_suggestion_status_is_accepted(): void
    {
        Http::fake([
            'georeference.it/api/v1/occurrences/*' => Http::response([
                'occurrenceID' => '1279592',
                'decimalLatitude' => 38.33,
                'decimalLongitude' => -8.85,
                'georef_status' => 'has_suggestion',
            ], 200),
        ]);

        $service = new GeoreferenceService();
        $data    = $service->normaliseOccurrence($service->lookupByGbifId('1279592'));

        $this->assertNotNull($data);
        $this->assertSame('has_suggestion', $data['coord_status']);
    }

    public function test_gbif_georeferenced_status_is_rejected(): void
    {
        // 'gbif_georeferenced' just mirrors GBIF's own coordinate — no correction, so skip it.
        Http::fake([
            'georeference.it/api/v1/occurrences/*' => Http::response([
                'occurrenceID' => '3014169301',
                'decimalLatitude' => 37.1,
                'decimalLongitude' => -7.6,
                'georef_status' => 'gbif_georeferenced',
            ], 200),
        ]);

        $service = new GeoreferenceService();
        $data    = $service->normaliseOccurrence($service->lookupByGbifId('3014169301'));

        $this->assertNull($data);
    }

    public function test_ungeoreferenced_status_is_rejected(): void
    {
        Http::fake([
            'georeference.it/api/v1/occurrences/*' => Http::response([
                'occurrenceID' => '35284750',
                'decimalLatitude' => null,
                'decimalLongitude' => null,
                'georef_status' => 'ungeoreferenced',
            ], 200),
        ]);

        $service = new GeoreferenceService();
        $data    = $service->normaliseOccurrence($service->lookupByGbifId('35284750'));

        $this->assertNull($data);
    }

    public function test_404_returns_null(): void
    {
        Http::fake([
            'georeference.it/api/v1/occurrences/*' => Http::response(null, 404),
        ]);

        $service = new GeoreferenceService();
        $this->assertNull($service->lookupByGbifId('999999999'));
    }

    public function test_unreachable_service_returns_null_and_flags_unavailable_after_repeated_failures(): void
    {
        Http::fake(['georeference.it/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7')]);

        $service = new GeoreferenceService();

        $this->assertNull($service->lookupByGbifId('1'));
        $this->assertFalse($service->isUnavailable());
        $service->lookupByGbifId('2');
        $service->lookupByGbifId('3');
        $this->assertTrue($service->isUnavailable());
    }

    public function test_a_successful_lookup_resets_the_failure_streak(): void
    {
        $calls = 0;
        Http::fake(['georeference.it/*' => function () use (&$calls) {
            if (++$calls === 3) return Http::response(['georef_status' => 'validated'], 200);
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 7');
        }]);

        $service = new GeoreferenceService();
        $service->lookupByGbifId('1');
        $service->lookupByGbifId('2');
        $service->lookupByGbifId('3'); // succeeds
        $service->lookupByGbifId('4');
        $service->lookupByGbifId('5');

        $this->assertFalse($service->isUnavailable());
    }

    public function test_lookup_many_is_one_request_and_keys_records_by_gbif_id(): void
    {
        Http::fake(['georeference.it/api/v1/occurrences*' => Http::response([
            'meta' => ['total' => 2],
            'data' => [
                ['occurrenceID' => '100', 'georef_status' => 'validated', 'decimalLatitude' => 1.0, 'decimalLongitude' => 2.0],
                ['occurrenceID' => '300', 'georef_status' => 'has_suggestion', 'decimalLatitude' => 3.0, 'decimalLongitude' => 4.0],
            ],
        ], 200)]);

        $records = (new GeoreferenceService())->lookupMany(['100', '200', '300', '100']);

        Http::assertSentCount(1);
        Http::assertSent(fn($r) => $r['occurrenceIDs'] === '100|200|300' && (int) $r['perPage'] === 3);
        $this->assertSame(['k100', 'k300'], array_keys($records)); // 200 unknown → absent
    }

    public function test_lookup_many_detects_a_server_that_ignores_the_filter(): void
    {
        // An older georeference.it ignores occurrenceIDs and returns a page of
        // arbitrary records with a huge total: must NOT be mistaken for results.
        Http::fake(['georeference.it/api/v1/occurrences*' => Http::response([
            'meta' => ['total' => 48213],
            'data' => [['occurrenceID' => '999', 'georef_status' => 'validated', 'decimalLatitude' => 9, 'decimalLongitude' => 9]],
        ], 200)]);

        $service = new GeoreferenceService();

        $this->assertNull($service->lookupMany(['100', '200']));
        $this->assertNull($service->lookupMany(['300']));
        Http::assertSentCount(1); // doesn't retry the batch path after learning it's unsupported
        $this->assertFalse($service->isUnavailable());
    }

    public function test_lookup_many_unreachable_counts_towards_unavailable(): void
    {
        Http::fake(['georeference.it/*' => fn() => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7')]);

        $service = new GeoreferenceService();
        $this->assertNull($service->lookupMany(['1']));
        $this->assertNull($service->lookupMany(['2']));
        $this->assertNull($service->lookupMany(['3']));

        $this->assertTrue($service->isUnavailable());
    }

    public function test_apply_record_matches_the_single_lookup_behaviour(): void
    {
        $specimen = new \App\Models\Specimen(['gbif_id' => '100']);
        $specimen = \Mockery::mock($specimen)->makePartial();
        $specimen->shouldReceive('update')->once()->with(\Mockery::on(fn($d) => $d['lat'] == 1.0 && $d['coord_source'] === 'georeference'));

        $service = new GeoreferenceService();

        $this->assertTrue($service->applyRecord($specimen, ['georef_status' => 'validated', 'decimalLatitude' => 1.0, 'decimalLongitude' => 2.0]));
        $this->assertFalse($service->applyRecord(new \App\Models\Specimen(['gbif_id' => '1']), null));
    }

    public function test_nothing_from_georeference_is_cached_between_requests(): void
    {
        // Citizens edit georeference.it constantly and those edits must reach
        // Expeditia on the very next sync: a fresh service instance (= the next
        // chained request) must always ask the server again, even if an older
        // server ignored the batch filter last time.
        Http::fake(['georeference.it/api/v1/occurrences*' => Http::response([
            'meta' => ['total' => 48213], 'data' => [],
        ], 200)]);

        $this->assertNull((new GeoreferenceService())->lookupMany(['1']));
        $this->assertNull((new GeoreferenceService())->lookupMany(['2']));

        Http::assertSentCount(2);
    }
}
