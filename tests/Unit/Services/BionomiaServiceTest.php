<?php

namespace Tests\Unit\Services;

use App\Models\Expedition;
use App\Models\Person;
use App\Models\Specimen;
use App\Services\BionomiaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BionomiaServiceTest extends TestCase
{
    private const HEADER = 'action,gbifID,datasetKey,license,occurrenceID,basisOfRecord,dateIdentified,decimalLatitude,decimalLongitude,country,countryCode,eventDate,year,kingdom,family,identifiedBy,institutionCode,collectionCode,catalogNumber,recordedBy,scientificName,typeStatus,recordedByID,identifiedByID,BIONOMIAcreatedBy,BIONOMIAcreatedByURI,BIONOMIAcreated';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('people', function ($t) {
            $t->increments('id');
            $t->string('wikidata_qid')->nullable();
            $t->string('bionomia_id')->nullable();
            $t->string('bionomia_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('specimens', function ($t) {
            $t->increments('id');
            $t->unsignedInteger('person_id')->nullable();
            $t->string('gbif_id', 20)->nullable()->unique();
            foreach (['dataset_key', 'occurrence_id', 'action', 'basis_of_record', 'license', 'scientific_name',
                'family', 'kingdom', 'type_status', 'recorded_by', 'recorded_by_id', 'identified_by',
                'identified_by_id', 'institution_code', 'collection_code', 'catalog_number', 'date_identified',
                'event_date', 'year', 'country', 'country_code', 'coord_source', 'gbif_url'] as $c) {
                $t->text($c)->nullable();
            }
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lon', 10, 7)->nullable();
            $t->unsignedInteger('coord_uncertainty_m')->nullable();
            $t->timestamps();
        });
        Schema::create('sync_state', function ($t) {
            $t->string('key', 64)->primary();
            $t->text('value')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        File::deleteDirectory(storage_path('app/bionomia'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/bionomia'));
        parent::tearDown();
    }

    private function csv(): string
    {
        return implode("\n", [
            self::HEADER,
            'recorded,1001,ds1,CC0_1_0,,PRESERVED_SPECIMEN,,10.5,20.5,Brazil,BR,1850-03-02,1850,Plantae,Poaceae,,K,K,K001,"Hooker, J.",Poa annua,,,,Bot,uri,2019',
            'recorded,1002,ds1,CC0_1_0,,PRESERVED_SPECIMEN,,,,Brazil,BR,-,,Plantae,Poaceae,,K,K,K002,"Hooker, J.","Poa ""quoted""' . "\n" . 'multiline",,,,Bot,uri,2019',
            'identified,1003,ds1,CC0_1_0,,PRESERVED_SPECIMEN,,,,,,,,Plantae,Poaceae,,K,K,K003,Someone,Poa x,,,,Bot,uri,2019',
            'recorded,1004,ds1,CC0_1_0,,PRESERVED_SPECIMEN,,1.1,2.2,Chile,CL,1851-01,1851,Plantae,Poaceae,,K,K,K004,"Hooker, J.",Poa y,,,,Bot,uri,2019',
            'recorded,1005,ds1,CC0_1_0,,PRESERVED_SPECIMEN,,3.3,4.4,Peru,PE,1852-05-06/1852-05-09,1852,Plantae,Poaceae,,K,K,K005,"Hooker, J.",Poa z,,,,Bot,uri,2019',
            '',
        ]);
    }

    private function make(): array
    {
        $person = Person::create(['wikidata_qid' => 'Q157501', 'bionomia_id' => 'Q157501']);
        $exp    = new Expedition();
        return [$person, $exp];
    }

    public function test_full_import_filters_to_recorded_and_respects_georeference_coords(): void
    {
        Http::fake(['bionomia.net/*' => Http::response($this->csv(), 200)]);

        // Already corrected by georeference.it: Bionomia must not overwrite it.
        Specimen::create(['gbif_id' => '1001', 'lat' => 99, 'lon' => 99, 'coord_source' => 'georeference']);
        // Exists with a GBIF coordinate and no Bionomia coordinate: must be kept.
        Specimen::create(['gbif_id' => '1002', 'lat' => 5, 'lon' => 6, 'coord_source' => 'gbif']);

        [$person, $exp] = $this->make();
        $total = (new BionomiaService())->syncAllPersonSpecimens($person, $exp);

        $this->assertSame(4, $total); // 1003 is "identified"
        $this->assertNull(Specimen::where('gbif_id', '1003')->first());

        $s1001 = Specimen::where('gbif_id', '1001')->first();
        $this->assertEquals(99, $s1001->lat);
        $this->assertSame('georeference', $s1001->coord_source);
        $this->assertSame($person->id, $s1001->person_id);
        $this->assertSame('1850-03-02', $s1001->event_date);

        $s1002 = Specimen::where('gbif_id', '1002')->first();
        $this->assertEquals(5, $s1002->lat);
        $this->assertSame('gbif', $s1002->coord_source);
        $this->assertNull($s1002->event_date);
        $this->assertSame("Poa \"quoted\"\nmultiline", $s1002->scientific_name);

        $s1004 = Specimen::where('gbif_id', '1004')->first();
        $this->assertEquals(1.1, $s1004->lat);
        $this->assertSame('bionomia', $s1004->coord_source);
        $this->assertSame('1851-01', $s1004->event_date);

        $this->assertSame('1852-05-06', Specimen::where('gbif_id', '1005')->value('event_date'));

        // Cleaned up and marked as synced
        $this->assertFileDoesNotExist(storage_path('app/bionomia/Q157501.csv'));
        $this->assertNull(DB::table('sync_state')->where('key', 'bionomia_cursor_Q157501')->value('value'));
        $this->assertNotNull($person->fresh()->bionomia_synced_at);
    }

    public function test_import_resumes_across_calls_without_redownloading(): void
    {
        Http::fake(['bionomia.net/*' => Http::response($this->csv(), 200)]);

        [$person, $exp] = $this->make();
        $service = new BionomiaService();
        $service->importBatchRows     = 2;
        $service->importBudgetSeconds = 0; // stop after every batch

        $r = $service->syncPersonSpecimensPage($person, $exp); // download phase
        $this->assertFalse($r['done']);
        $this->assertSame(0, $r['count']);
        $this->assertSame('0', DB::table('sync_state')->where('key', 'bionomia_cursor_Q157501')->value('value'));

        $r = $service->syncPersonSpecimensPage($person, $exp); // first batch
        $this->assertFalse($r['done']);
        $this->assertSame(2, $r['count']);
        $this->assertSame(2, Specimen::count());

        $calls = 1;
        while (!$r['done'] && $calls++ < 10) {
            $r = $service->syncPersonSpecimensPage($person, $exp);
        }

        $this->assertTrue($r['done']);
        $this->assertSame(4, Specimen::count());
        Http::assertSentCount(1); // CSV downloaded once, however many import calls
    }

    public function test_cloudflare_challenge_does_not_import_or_mark_synced(): void
    {
        Http::fake(['bionomia.net/*' => Http::response('<html>Just a moment...</html>', 403)]);

        [$person, $exp] = $this->make();
        $r = (new BionomiaService())->syncPersonSpecimensPage($person, $exp);

        $this->assertTrue($r['done']);
        $this->assertSame(0, Specimen::count());
        $this->assertNull($person->fresh()->bionomia_synced_at);
        $this->assertNull(DB::table('sync_state')->where('key', 'bionomia_cursor_Q157501')->value('value'));
    }

    public function test_up_to_date_person_is_skipped_without_downloading_csv(): void
    {
        Http::fake([
            'api.bionomia.net/*' => Http::response(['dateModified' => '2020-01-01T00:00:00+00:00'], 200),
            'bionomia.net/*'     => Http::response($this->csv(), 200),
        ]);

        [$person, $exp] = $this->make();
        $person->bionomia_synced_at = '2021-01-01 00:00:00';
        $person->save();

        $r = (new BionomiaService())->syncPersonSpecimensPage($person, $exp);

        $this->assertTrue($r['done']);
        $this->assertTrue($r['skipped']);
        Http::assertNotSent(fn($req) => str_contains($req->url(), '/specimens.csv'));
    }
}
