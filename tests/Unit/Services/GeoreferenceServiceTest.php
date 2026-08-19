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
}
