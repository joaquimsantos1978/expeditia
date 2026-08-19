<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\Request;

class PersonController extends Controller
{
public function index(Request $request)
{
    $q = trim($request->input('q', ''));
 
$query = Person::whereNotNull('name')
    ->withCount([
        'expeditions',
        'specimens as specimens_count' => function ($q) {
            $q->join('expedition_occurrences',
                     'specimens.id', '=', 'expedition_occurrences.specimen_id');
        },
    ]);

    if ($q) {
        if ($query->getModel()->whereNotNull('search_text')->exists()) {
            $query->whereFullText('search_text', $q);
        } else {
            // Fallback enquanto o search_text não estiver populado
            $query->where(function ($sql) use ($q) {
                $sql->where('name',    'like', "%{$q}%")
                    ->orWhere('aliases', 'like', "%{$q}%")
                    ->orWhere('wikidata_qid', 'like', "%{$q}%");
            });
        }
    }
    
 
    // When searching, sort by name relevance; otherwise alphabetical
    if ($q) {
        $query->orderByRaw("CASE
            WHEN name = ? THEN 0
            WHEN name LIKE ? THEN 1
            WHEN name LIKE ? THEN 2
            ELSE 3
        END, name", [$q, $q . '%', '%' . $q . '%']);
    } else {
        $query->orderBy('name');
    }

    $people = $query->paginate(24)->withQueryString();
 
    return view('people.index', compact('people'));
}

public function show(string $qid)
{
    $person = Person::where('wikidata_qid', $qid)
        ->with(['expeditions'])
        ->firstOrFail();

    $expeditionsData = $person->expeditions->values()->map(fn($exp) => [
        'id'    => $exp->id,
        'qid'   => $exp->wikidata_qid,
        'label' => $exp->label ?? $exp->wikidata_qid,
    ]);

    return view('people.show', compact('person', 'expeditionsData'));
}

public function personMapData(string $qid)
{
    $person = Person::where('wikidata_qid', $qid)->with('expeditions')->firstOrFail();
    $items  = [];

    foreach ($person->expeditions->values() as $expIndex => $expedition) {
        // Points
        foreach ($expedition->mapItems()->where('type', 'point')
            ->select('map_items.id','map_items.type','map_items.lat','map_items.lon',
                     'map_items.name','map_items.date_label','map_items.order','map_items.image')
            ->get() as $item) {
            $arr = $item->toArray();
            $arr['exp_index']      = $expIndex;
            $arr['expedition_qid'] = $expedition->wikidata_qid;
            $items[] = $arr;
        }

        // Specimens for this person
        $expedition->mapItems()
            ->where('map_items.type', 'specimen')
            ->leftJoin('specimens', fn($j) => $j->on('map_items.source_id','=','specimens.id')->where('map_items.type','=','specimen'))
            ->where('specimens.person_id', $person->id)
            ->select('map_items.id','map_items.type','map_items.lat','map_items.lon',
                     'map_items.name','map_items.date_label','map_items.order',
                     'specimens.image as specimen_image','specimens.gbif_id',
                     'specimens.locality','specimens.verbatim_locality',
                     'specimens.recorded_by',
                     'specimens.institution_code','specimens.collection_code',
                     'specimens.coord_uncertainty_m')
            ->orderBy('map_items.order')
            ->chunk(500, function($chunk) use ($expIndex, $expedition, &$items) {
                foreach ($chunk as $item) {
                    $arr = $item->toArray();
                    $arr['exp_index']      = $expIndex;
                    $arr['expedition_qid'] = $expedition->wikidata_qid;
                    $items[] = $arr;
                }
            });
    }

    return response()->json($items);
}

public function personItemDetail(string $qid, int $id)
{
    $person = Person::where('wikidata_qid', $qid)->firstOrFail();

    // Find which expedition this item belongs to
    $item = \DB::table('map_items')
        ->join('expeditions', 'map_items.expedition_id', '=', 'expeditions.id')
        ->leftJoin('specimens', fn($j) => $j->on('map_items.source_id','=','specimens.id')->where('map_items.type','=','specimen'))
        ->where('map_items.id', $id)
        ->select(
            'map_items.*',
            'expeditions.wikidata_qid as expedition_qid',
            'specimens.gbif_id','specimens.image as specimen_image',
            'specimens.locality','specimens.verbatim_locality',
            'specimens.higher_geography','specimens.state_province',
            'specimens.county','specimens.municipality',
            'specimens.scientific_name','specimens.family','specimens.kingdom',
            'specimens.recorded_by','specimens.identified_by',
            'specimens.institution_code','specimens.collection_code',
            'specimens.catalog_number','specimens.basis_of_record','specimens.type_status',
            'specimens.country','specimens.country_code',
            'specimens.event_date','specimens.year','specimens.dataset_key',
            'specimens.occurrence_id','specimens.date_identified','specimens.license',
            'specimens.coord_uncertainty_m',
        )
        ->first();

    abort_unless($item, 404);
    return response()->json($item);
}

public function exportCsv(string $qid)
{
    $person = Person::where('wikidata_qid', $qid)->firstOrFail();

$specimens = $person->specimens()
    ->join('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
    ->leftJoin('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
    ->select('specimens.*', 'expeditions.wikidata_qid as expedition_qid', 'expeditions.label as expedition_label')
    ->orderBy('specimens.event_date')
    ->get();

    $filename = str_replace(' ', '_', $person->name ?? $qid) . '_specimens.csv';

    $headers = [
        'Content-Type'        => 'text/csv',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ];

    $callback = function() use ($specimens) {
        $handle = fopen('php://output', 'w');

        fputcsv($handle, [
            'expedition_qid', 'expedition_label',
            'scientific_name', 'family', 'kingdom', 'type_status',
            'recorded_by', 'identified_by', 'date_identified',
            'locality', 'verbatim_locality', 'country', 'country_code',
            'lat', 'lon', 'event_date', 'year',
            'institution_code', 'collection_code', 'catalog_number',
            'basis_of_record', 'gbif_id', 'gbif_url',
        ]);

        foreach ($specimens as $s) {
            fputcsv($handle, [
                $s->expedition_qid,   $s->expedition_label,
                $s->scientific_name,  $s->family,            $s->kingdom,
                $s->type_status,      $s->recorded_by,       $s->identified_by,
                $s->date_identified,  $s->locality,          $s->verbatim_locality,
                $s->country,          $s->country_code,      $s->lat,
                $s->lon,              $s->event_date,        $s->year,
                $s->institution_code, $s->collection_code,   $s->catalog_number,
                $s->basis_of_record,  $s->gbif_id,           $s->gbif_url,
            ]);
        }

        fclose($handle);
    };

    return response()->stream($callback, 200, $headers);
}

public function exportJson(string $qid)
{
    $person = Person::where('wikidata_qid', $qid)
        ->with('expeditions')
        ->firstOrFail();

$specimens = $person->specimens()
    ->join('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
    ->leftJoin('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
    ->select('specimens.*', 'expeditions.wikidata_qid as expedition_qid', 'expeditions.label as expedition_label')
    ->orderBy('specimens.event_date')
    ->get();

    return response()->json([
        'person' => [
            'wikidata_qid' => $person->wikidata_qid,
            'name'         => $person->name,
            'orcid'        => $person->orcid,
            'wikidata_url' => 'https://www.wikidata.org/wiki/' . $person->wikidata_qid,
        ],
        'expeditions' => $person->expeditions->map(fn($e) => [
            'wikidata_qid' => $e->wikidata_qid,
            'label'        => $e->label,
            'start_time'   => $e->start_time,
            'end_time'     => $e->end_time,
        ]),
        'total'     => $specimens->count(),
        'specimens' => $specimens,
    ]);
}

}
