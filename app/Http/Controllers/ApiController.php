<?php

namespace App\Http\Controllers;

use App\Models\Expedition;
use App\Models\Person;

class ApiController extends Controller
{
    public function expeditions()
    {
        $expeditions = Expedition::whereNull('retired_at')
            ->whereNotNull('synced_at')
            ->withCount(['people', 'mapItems'])
            ->orderBy('start_time')
            ->get()
            ->map(fn($e) => [
                'wikidata_qid' => $e->wikidata_qid,
                'label'        => $e->label,
                'description'  => $e->description,
                'start_time'   => $e->start_time,
                'end_time'     => $e->end_time,
                'people_count' => $e->people_count,
                'items_count'  => $e->map_items_count,
                'wikidata_url' => 'https://www.wikidata.org/wiki/' . $e->wikidata_qid,
                'api_url'      => route('api.expedition', $e->wikidata_qid),
                'items_url'    => route('api.expedition.items', $e->wikidata_qid),
            ]);

        return response()->json([
            'count'       => $expeditions->count(),
            'expeditions' => $expeditions,
        ]);
    }

    public function expedition(string $qid)
    {
        $expedition = Expedition::where('wikidata_qid', $qid)
            ->with('people')
            ->firstOrFail();

        return response()->json([
            'wikidata_qid' => $expedition->wikidata_qid,
            'label'        => $expedition->label,
            'description'  => $expedition->description,
            'image'        => $expedition->image,
            'start_time'   => $expedition->start_time,
            'end_time'     => $expedition->end_time,
            'wikidata_url' => 'https://www.wikidata.org/wiki/' . $expedition->wikidata_qid,
            'people'       => $expedition->people->map(fn($p) => [
                'wikidata_qid' => $p->wikidata_qid,
                'name'         => $p->name,
                'orcid'        => $p->orcid,
                'wikidata_url' => 'https://www.wikidata.org/wiki/' . $p->wikidata_qid,
                'api_url'      => route('api.person', $p->wikidata_qid),
            ]),
            'items_url'    => route('api.expedition.items', $qid),
            'export_csv'   => route('expeditions.export.csv',  $qid),
            'export_json'  => route('expeditions.export.json', $qid),
        ]);
    }

    public function items(string $qid)
    {
        $expedition = Expedition::where('wikidata_qid', $qid)->firstOrFail();

        $items = $expedition->mapItems()
            ->leftJoin('specimens', function($join) {
                $join->on('map_items.source_id', '=', 'specimens.id')
                     ->where('map_items.type', '=', 'specimen');
            })
            ->select(
                'map_items.type', 'map_items.order', 'map_items.date_label',
                'map_items.name', 'map_items.lat', 'map_items.lon',
                'specimens.gbif_id', 'specimens.scientific_name', 'specimens.family',
                'specimens.kingdom', 'specimens.recorded_by', 'specimens.identified_by',
                'specimens.institution_code', 'specimens.collection_code', 'specimens.catalog_number',
                'specimens.basis_of_record', 'specimens.type_status',
                'specimens.locality', 'specimens.verbatim_locality',
                'specimens.country', 'specimens.country_code',
                'specimens.event_date', 'specimens.year', 'specimens.gbif_url',
                'specimens.coord_source', 'specimens.coord_status', 'specimens.coord_uncertainty_m',
            )
            ->get();

        return response()->json([
            'expedition'   => $expedition->wikidata_qid,
            'total'        => $items->count(),
            'items'        => $items,
        ]);
    }

    public function person(string $qid)
    {
        $person = Person::where('wikidata_qid', $qid)
            ->with('expeditions')
            ->firstOrFail();

        return response()->json([
            'wikidata_qid' => $person->wikidata_qid,
            'name'         => $person->name,
            'description'  => $person->description,
            'date_birth'   => $person->date_birth,
            'date_death'   => $person->date_death,
            'place_birth'  => $person->place_birth,
            'place_death'  => $person->place_death,
            'occupation'   => $person->occupation,
            'orcid'        => $person->orcid,
            'wikidata_url' => 'https://www.wikidata.org/wiki/' . $person->wikidata_qid,
            'expeditions'  => $person->expeditions->map(fn($e) => [
                'wikidata_qid' => $e->wikidata_qid,
                'label'        => $e->label,
                'start_time'   => $e->start_time,
                'end_time'     => $e->end_time,
                'api_url'      => route('api.expedition', $e->wikidata_qid),
            ]),
        ]);
    }

public function personSpecimens(string $qid)
{
    $person = Person::where('wikidata_qid', $qid)->firstOrFail();

    $specimens = $person->specimens()
        ->leftJoin('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
        ->leftJoin('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
        ->select(
            'specimens.*',
            'expeditions.wikidata_qid as expedition_qid',
            'expeditions.label as expedition_label'
        )
        ->orderBy('specimens.event_date')
        ->get();

    return response()->json([
        'person'    => $person->wikidata_qid,
        'total'     => $specimens->count(),
        'specimens' => $specimens,
    ]);
}

    
public function institution(string $code)
{
    $code = urldecode($code);
    $total = \DB::table('specimens')->where('institution_code', $code)->count();
    abort_unless($total > 0, 404);

    $expeditions = \DB::table('expeditions')
        ->join('expedition_occurrences', 'expeditions.id', '=', 'expedition_occurrences.expedition_id')
        ->join('specimens', 'expedition_occurrences.specimen_id', '=', 'specimens.id')
        ->where('specimens.institution_code', $code)
        ->whereNull('expeditions.retired_at')
        ->select('expeditions.wikidata_qid', 'expeditions.label',
                 \DB::raw('COUNT(DISTINCT specimens.id) as specimens_count'))
        ->groupBy('expeditions.wikidata_qid', 'expeditions.label')
        ->orderByDesc('specimens_count')
        ->get();

    return response()->json([
        'institution_code' => $code,
        'specimens_total'  => $total,
        'expeditions'      => $expeditions,
        'url'              => url('/institution/' . urlencode($code)),
    ]);
}

public function institutionSpecimens(string $code)
{
    $code = urldecode($code);
    $specimens = \DB::table('specimens')
        ->join('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
        ->leftJoin('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
        ->where('specimens.institution_code', $code)
        ->select('specimens.*',
                 'expeditions.wikidata_qid as expedition_qid',
                 'expeditions.label as expedition_label')
        ->orderBy('specimens.event_date')
        ->get();
    return response()->json(['institution_code' => $code, 'total' => count($specimens), 'specimens' => $specimens]);
}

}
