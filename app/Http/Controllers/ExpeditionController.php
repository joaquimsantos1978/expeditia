<?php

namespace App\Http\Controllers;

use App\Models\Expedition;
use Illuminate\Http\Request;

class ExpeditionController extends Controller
{
// ── ExpeditionController@index ────────────────────────────────────────────────
public function index(Request $request)
{
    $q = trim($request->input('q', ''));
 
    $query = Expedition::withCount(['people', 'mapItems'])
        ->whereNull('retired_at');
 
    if ($q) {
        if ($query->getModel()->whereNotNull('search_text')->exists()) {
            $query->whereFullText('search_text', $q);
        } else {
            // Fallback enquanto o search_text não estiver populado
            $query->where(function ($sql) use ($q) {
                $sql->where('label',       'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('wikidata_qid', 'like', "%{$q}%");
            });
        }
    }
 
    if ($q) {
        $query->orderByRaw("CASE
            WHEN label = ? THEN 0
            WHEN label LIKE ? THEN 1
            WHEN label LIKE ? THEN 2
            ELSE 3
        END, synced_at DESC", [$q, $q . '%', '%' . $q . '%']);
    } else {
        $query->orderBy('synced_at', 'DESC');
    }

    $expeditions = $query->paginate(24)->withQueryString();
 
    return view('expeditions.index', compact('expeditions'));
}

public function show(string $qid)
{
    $expedition = Expedition::where('wikidata_qid', $qid)
        ->with('people')
        ->firstOrFail();

$mapItems = $expedition->mapItems()
    ->leftJoin('specimens', function($join) {
        $join->on('map_items.source_id', '=', 'specimens.id')
             ->where('map_items.type', '=', 'specimen');
    })
    ->select(
        'map_items.*',
        'specimens.gbif_id',
        'specimens.image as specimen_image',
        'specimens.locality',
        'specimens.verbatim_locality',
        'specimens.scientific_name',
        'specimens.family',
        'specimens.kingdom',
        'specimens.recorded_by',
        'specimens.identified_by',
        'specimens.institution_code',
        'specimens.collection_code',
        'specimens.catalog_number',
        'specimens.basis_of_record',
        'specimens.type_status',
        'specimens.country',
        'specimens.country_code',
        'specimens.event_date',
        'specimens.year',
        'specimens.dataset_key',
        'specimens.occurrence_id',
        'specimens.date_identified',
        'specimens.license',
    )
    ->get();

$totalMapItems = $expedition->mapItems()->count();

    return view('expeditions.show', compact('expedition', 'totalMapItems'));
}

public function mapData(string $qid)
{
    $expedition = Expedition::where('wikidata_qid', $qid)->firstOrFail();

    $items = $expedition->mapItems()
        ->leftJoin('specimens', function($join) {
            $join->on('map_items.source_id', '=', 'specimens.id')
                 ->where('map_items.type', '=', 'specimen');
        })
        ->select(
            'map_items.id', 'map_items.type',
            'map_items.lat', 'map_items.lon',
            'map_items.name', 'map_items.date_label',
            'map_items.order', 'map_items.description', 'map_items.image',
            'specimens.image as specimen_image',
            'specimens.gbif_id',
            'specimens.locality',
            'specimens.verbatim_locality',
            'specimens.scientific_name',
            'specimens.recorded_by',
            'specimens.institution_code',
            'specimens.collection_code',
            'specimens.coord_uncertainty_m',
        )
        ->orderBy('map_items.order')
        ->get();

    return response()->json($items);
}

public function itemDetail(string $qid, int $id)
{
    $expedition = Expedition::where('wikidata_qid', $qid)->firstOrFail();

    $item = $expedition->mapItems()
        ->where('map_items.id', $id)
        ->leftJoin('specimens', function($join) {
            $join->on('map_items.source_id', '=', 'specimens.id')
                 ->where('map_items.type', '=', 'specimen');
        })
        ->select(
            'map_items.*',
            'specimens.gbif_id',
            'specimens.image as specimen_image',
            'specimens.locality', 'specimens.verbatim_locality',
            'specimens.higher_geography', 'specimens.state_province',
            'specimens.county', 'specimens.municipality',
            'specimens.scientific_name', 'specimens.family', 'specimens.kingdom',
            'specimens.recorded_by', 'specimens.identified_by',
            'specimens.institution_code', 'specimens.collection_code',
            'specimens.catalog_number', 'specimens.basis_of_record',
            'specimens.type_status', 'specimens.country', 'specimens.country_code',
            'specimens.event_date', 'specimens.year', 'specimens.dataset_key',
            'specimens.occurrence_id', 'specimens.date_identified', 'specimens.license',
            'specimens.coord_uncertainty_m',
        )
        ->firstOrFail();

    return response()->json($item);
}



public function exportCsv(string $qid)
{
    $expedition = Expedition::where('wikidata_qid', $qid)->firstOrFail();

    $mapItems = $expedition->mapItems()
        ->leftJoin('specimens', function($join) {
            $join->on('map_items.source_id', '=', 'specimens.id')
                 ->where('map_items.type', '=', 'specimen');
        })
        ->select(
            'map_items.type', 'map_items.date_label', 'map_items.name',
            'map_items.lat', 'map_items.lon',
            'specimens.gbif_id', 'specimens.scientific_name', 'specimens.family',
            'specimens.kingdom', 'specimens.recorded_by', 'specimens.identified_by',
            'specimens.institution_code', 'specimens.collection_code', 'specimens.catalog_number',
            'specimens.basis_of_record', 'specimens.type_status',
            'specimens.locality', 'specimens.verbatim_locality',
            'specimens.country', 'specimens.country_code',
            'specimens.event_date', 'specimens.year', 'specimens.gbif_url',
        )
        ->get();

    $filename = str_replace(' ', '_', $expedition->label ?? $qid) . '.csv';

    $headers = [
        'Content-Type'        => 'text/csv',
        'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ];

    $callback = function() use ($mapItems) {
        $handle = fopen('php://output', 'w');

        // Header row
        fputcsv($handle, [
            'type', 'date', 'name', 'scientific_name', 'family', 'kingdom',
            'recorded_by', 'identified_by', 'type_status',
            'locality', 'verbatim_locality', 'country', 'country_code',
            'lat', 'lon', 'institution_code', 'collection_code', 'catalog_number',
            'basis_of_record', 'event_date', 'year', 'gbif_id', 'gbif_url',
        ]);

        foreach ($mapItems as $item) {
            fputcsv($handle, [
                $item->type,             $item->date_label,        $item->name,
                $item->scientific_name,  $item->family,            $item->kingdom,
                $item->recorded_by,      $item->identified_by,     $item->type_status,
                $item->locality,         $item->verbatim_locality, $item->country,
                $item->country_code,     $item->lat,               $item->lon,
                $item->institution_code, $item->collection_code,   $item->catalog_number,
                $item->basis_of_record,  $item->event_date,        $item->year,
                $item->gbif_id,          $item->gbif_url,
            ]);
        }

        fclose($handle);
    };

    return response()->stream($callback, 200, $headers);
}

public function exportJson(string $qid)
{
    $expedition = Expedition::where('wikidata_qid', $qid)->firstOrFail();

    $mapItems = $expedition->mapItems()
        ->leftJoin('specimens', function($join) {
            $join->on('map_items.source_id', '=', 'specimens.id')
                 ->where('map_items.type', '=', 'specimen');
        })
        ->select(
            'map_items.type', 'map_items.date_label', 'map_items.name',
            'map_items.lat', 'map_items.lon',
            'specimens.gbif_id', 'specimens.scientific_name', 'specimens.family',
            'specimens.kingdom', 'specimens.recorded_by', 'specimens.identified_by',
            'specimens.institution_code', 'specimens.collection_code', 'specimens.catalog_number',
            'specimens.basis_of_record', 'specimens.type_status',
            'specimens.locality', 'specimens.verbatim_locality',
            'specimens.country', 'specimens.country_code',
            'specimens.event_date', 'specimens.year', 'specimens.gbif_url',
        )
        ->get();

    return response()->json([
        'expedition' => [
            'wikidata_qid' => $expedition->wikidata_qid,
            'label'        => $expedition->label,
            'description'  => $expedition->description,
            'start_time'   => $expedition->start_time,
            'end_time'     => $expedition->end_time,
            'wikidata_url' => 'https://www.wikidata.org/wiki/' . $expedition->wikidata_qid,
        ],
        'items' => $mapItems,
    ]);
}
}