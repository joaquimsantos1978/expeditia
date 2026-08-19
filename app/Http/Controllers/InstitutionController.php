<?php

namespace App\Http\Controllers;

use App\Models\Institution;
use App\Services\GbifService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstitutionController extends Controller
{
    public function __construct(protected GbifService $gbif) {}

    // ── Index ──────────────────────────────────────────────────────────────────
    // Now reads from the pre-built `institutions` table — no live GBIF calls,
    // no specimen joins. Fast regardless of dataset size.
    public function index(Request $request)
    {
        $q = trim($request->input('q', ''));

        $query = Institution::query()->orderByDesc('specimens_count');

        if ($q) {
            $query->where(function ($qb) use ($q) {
                $qb->where('code',    'like', "%{$q}%")
                   ->orWhere('name',    'like', "%{$q}%")
                   ->orWhere('country', 'like', "%{$q}%")
                   ->orWhere('city',    'like', "%{$q}%");
            });
        }

        $institutions = $query->paginate(30)->withQueryString();

        return view('institutions.index', compact('institutions', 'q'));
    }

    // ── Show ───────────────────────────────────────────────────────────────────
    public function show(string $code)
    {
        $code = urldecode($code);
        abort_unless(DB::table('specimens')->where('institution_code', $code)->exists(), 404);

        // Prefer the cached row; fall back to live GBIF only if missing
        $inst = Institution::where('code', $code)->first();
        $institutionInfo = $inst ? [
            'name'     => $inst->name,
            'country'  => $inst->country,
            'city'     => $inst->city,
            'homepage' => $inst->homepage,
            'gbif_key' => $inst->gbif_key,
        ] : null;

        // Expeditions that have specimens from this institution (ordered by count)
        $expeditions = DB::table('expeditions')
            ->join('expedition_occurrences', 'expeditions.id', '=', 'expedition_occurrences.expedition_id')
            ->join('specimens', 'expedition_occurrences.specimen_id', '=', 'specimens.id')
            ->where('specimens.institution_code', $code)
            ->whereNull('expeditions.retired_at')
            ->select(
                'expeditions.id', 'expeditions.wikidata_qid', 'expeditions.label',
                'expeditions.start_time', 'expeditions.end_time', 'expeditions.image',
                DB::raw('COUNT(DISTINCT specimens.id) as specimens_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN specimens.image IS NOT NULL THEN specimens.id END) as images_count'),
            )
            ->groupBy('expeditions.id', 'expeditions.wikidata_qid', 'expeditions.label',
                      'expeditions.start_time', 'expeditions.end_time', 'expeditions.image')
            ->orderByDesc('specimens_count')
            ->get();

        $expeditionsData = $expeditions->values()->map(fn($exp, $i) => [
            'id'    => $exp->id,
            'qid'   => $exp->wikidata_qid,
            'label' => $exp->label ?? $exp->wikidata_qid,
        ]);

        $collections = DB::table('specimens')
            ->where('institution_code', $code)
            ->whereNotNull('collection_code')->where('collection_code', '!=', '')
            ->select(
                'collection_code',
                DB::raw('COUNT(*) as specimens_count'),
                DB::raw('COUNT(CASE WHEN image IS NOT NULL THEN 1 END) as images_count'),
            )
            ->groupBy('collection_code')->orderByDesc('specimens_count')->get();

        $totalSpecimens = $inst?->specimens_count
            ?? DB::table('specimens')->where('institution_code', $code)->count();

        $totalMapItems = DB::table('map_items')
            ->join('specimens', fn($j) => $j->on('map_items.source_id', '=', 'specimens.id')
                ->where('map_items.type', '=', 'specimen'))
            ->join('expeditions', 'map_items.expedition_id', '=', 'expeditions.id')
            ->where('specimens.institution_code', $code)
            ->whereNull('expeditions.retired_at')
            ->count();

        return view('institutions.show', compact(
            'code', 'institutionInfo', 'collections', 'expeditions', 'expeditionsData',
            'totalSpecimens', 'totalMapItems'
        ));
    }

    // ── Map data ───────────────────────────────────────────────────────────────
    public function mapData(string $code)
    {
        $code = urldecode($code);

        $expIds = DB::table('expeditions')
            ->join('expedition_occurrences', 'expeditions.id', '=', 'expedition_occurrences.expedition_id')
            ->join('specimens', 'expedition_occurrences.specimen_id', '=', 'specimens.id')
            ->where('specimens.institution_code', $code)
            ->whereNull('expeditions.retired_at')
            ->select('expeditions.id', DB::raw('COUNT(DISTINCT specimens.id) as cnt'))
            ->groupBy('expeditions.id')
            ->orderByDesc('cnt')
            ->pluck('expeditions.id');

        $expIndex = $expIds->values()->flip();

        $items = DB::table('map_items')
            ->join('specimens', fn($j) => $j->on('map_items.source_id', '=', 'specimens.id')
                ->where('map_items.type', '=', 'specimen'))
            ->join('expeditions', 'map_items.expedition_id', '=', 'expeditions.id')
            ->where('specimens.institution_code', $code)
            ->whereNull('expeditions.retired_at')
            ->select(
                'map_items.id', 'map_items.type',
                'map_items.lat', 'map_items.lon',
                'map_items.name', 'map_items.date_label',
                'map_items.order',
                'expeditions.id as expedition_id',
                'expeditions.wikidata_qid as expedition_qid',
                'expeditions.label as expedition_label',
                'specimens.image as specimen_image', 'specimens.gbif_id',
                'specimens.locality', 'specimens.verbatim_locality',
                'specimens.recorded_by', 'specimens.scientific_name',
                'specimens.institution_code', 'specimens.collection_code',
                'specimens.coord_uncertainty_m',
            )
            ->orderBy('expeditions.id')
            ->orderBy('map_items.order')
            ->limit(2000)
            ->get()
            ->map(function ($item) use ($expIndex) {
                $item->exp_index = $expIndex->get($item->expedition_id, 0);
                return $item;
            });

        return response()->json($items);
    }

    // ── Item detail ────────────────────────────────────────────────────────────
    public function itemDetail(string $code, int $id)
    {
        $code = urldecode($code);

        $item = DB::table('map_items')
            ->join('expeditions', 'map_items.expedition_id', '=', 'expeditions.id')
            ->leftJoin('specimens', fn($j) => $j->on('map_items.source_id', '=', 'specimens.id')
                ->where('map_items.type', '=', 'specimen'))
            ->where('map_items.id', $id)
            ->where('specimens.institution_code', $code)
            ->select(
                'map_items.*',
                'expeditions.wikidata_qid as expedition_qid',
                'specimens.gbif_id', 'specimens.image as specimen_image',
                'specimens.locality', 'specimens.verbatim_locality',
                'specimens.higher_geography', 'specimens.state_province',
                'specimens.county', 'specimens.municipality',
                'specimens.scientific_name', 'specimens.family', 'specimens.kingdom',
                'specimens.recorded_by', 'specimens.identified_by',
                'specimens.institution_code', 'specimens.collection_code',
                'specimens.catalog_number', 'specimens.basis_of_record', 'specimens.type_status',
                'specimens.country', 'specimens.country_code',
                'specimens.event_date', 'specimens.year', 'specimens.dataset_key',
                'specimens.occurrence_id', 'specimens.date_identified', 'specimens.license',
                'specimens.coord_uncertainty_m',
            )
            ->first();

        abort_unless($item, 404);
        return response()->json($item);
    }

    // ── CSV export ─────────────────────────────────────────────────────────────
    public function exportCsv(string $code)
    {
        $code      = urldecode($code);
        $specimens = $this->getExportData($code);
        $filename  = str_replace(['/', ' '], '_', $code) . '_specimens.csv';

        return response()->stream(function () use ($specimens) {
            $h = fopen('php://output', 'w');
            fputcsv($h, [
                'institution_code', 'collection_code', 'catalog_number',
                'expedition_qid', 'expedition_label',
                'scientific_name', 'family', 'kingdom', 'type_status',
                'recorded_by', 'identified_by', 'date_identified',
                'locality', 'verbatim_locality', 'country', 'country_code',
                'lat', 'lon', 'event_date', 'year',
                'basis_of_record', 'gbif_id', 'gbif_url',
            ]);
            foreach ($specimens as $s) {
                fputcsv($h, [
                    $s->institution_code, $s->collection_code,  $s->catalog_number,
                    $s->expedition_qid,   $s->expedition_label,
                    $s->scientific_name,  $s->family,           $s->kingdom,
                    $s->type_status,      $s->recorded_by,      $s->identified_by,
                    $s->date_identified,  $s->locality,         $s->verbatim_locality,
                    $s->country,          $s->country_code,     $s->lat, $s->lon,
                    $s->event_date,       $s->year,
                    $s->basis_of_record,  $s->gbif_id,          $s->gbif_url,
                ]);
            }
            fclose($h);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // ── JSON export ────────────────────────────────────────────────────────────
    public function exportJson(string $code)
    {
        $code      = urldecode($code);
        $specimens = $this->getExportData($code);
        return response()->json([
            'institution_code' => $code,
            'total'            => count($specimens),
            'specimens'        => $specimens,
        ]);
    }

    private function getExportData(string $code): \Illuminate\Support\Collection
    {
        return DB::table('specimens')
            ->join('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
            ->leftJoin('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
            ->where('specimens.institution_code', $code)
            ->select(
                'specimens.institution_code', 'specimens.collection_code', 'specimens.catalog_number',
                'expeditions.wikidata_qid as expedition_qid', 'expeditions.label as expedition_label',
                'specimens.scientific_name', 'specimens.family', 'specimens.kingdom',
                'specimens.type_status', 'specimens.recorded_by', 'specimens.identified_by',
                'specimens.date_identified', 'specimens.locality', 'specimens.verbatim_locality',
                'specimens.country', 'specimens.country_code', 'specimens.lat', 'specimens.lon',
                'specimens.event_date', 'specimens.year', 'specimens.basis_of_record',
                'specimens.gbif_id', 'specimens.gbif_url',
            )
            ->orderBy('specimens.event_date')
            ->get();
    }
}
