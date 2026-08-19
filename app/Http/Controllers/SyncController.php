<?php

namespace App\Http\Controllers;

use App\Models\Expedition;
use App\Models\Person;
use App\Models\Point;
use App\Models\Specimen;
use App\Models\MapItem;
use App\Models\ExpeditionOccurrence;
use App\Services\WikidataService;
use App\Services\GbifService;
use App\Services\BionomiaService;
use App\Services\GeoreferenceService;
use App\Services\MapBuilderService;
use App\Services\SearchService;
use App\Services\InstitutionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function __construct(
        protected WikidataService   $wikidata,
        protected GbifService       $gbif,
        protected BionomiaService   $bionomia,
        protected GeoreferenceService $georeference,
        protected MapBuilderService $mapBuilder,
        protected SearchService     $search,
        protected InstitutionService $institutions,
    ) {}

    // -------------------------------------------------------------------------
    // Force full re-sync of all expeditions (weekly cron entry point)
    // -------------------------------------------------------------------------
    public function reindexAll(Request $request)
    {
        $this->checkToken($request);

        Expedition::whereNull('retired_at')->update(['synced_at' => null]);

        $next = Expedition::whereNull('synced_at')->whereNull('retired_at')->first();

        if (!$next) {
            return response()->json(['status' => 'done', 'message' => 'No expeditions found.']);
        }

        $nextUrl = route('sync.expedition.meta', ['id' => $next->id, 'token' => $request->token]);

        DB::table('sync_state')->updateOrInsert(
            ['key' => 'sync_next_url'],
            ['value' => $nextUrl]
        );

        return response()->json(['status' => 'ok', 'next' => $nextUrl]);
    }

    // -------------------------------------------------------------------------
    // Step 1 — Sync list of all expeditions from Wikidata SPARQL
    // -------------------------------------------------------------------------
    public function syncExpeditionList(Request $request)
    {
        $this->checkToken($request);

        $qids = $this->wikidata->getAllExpeditionQids(['Q366301']);

        if (empty($qids)) {
            return response()->json(['status' => 'error', 'message' => 'No QIDs returned from Wikidata.']);
        }

        foreach ($qids as $qid) {
            Expedition::firstOrCreate(
                ['wikidata_qid' => $qid],
                ['label' => $qid]
            );
        }

        Expedition::whereNotIn('wikidata_qid', $qids)->whereNull('retired_at')->update(['retired_at' => now()]);
        Expedition::whereIn('wikidata_qid', $qids)->whereNotNull('retired_at')->update(['retired_at' => null]);

        $next = Expedition::whereNull('synced_at')->whereNull('retired_at')->first();

        if (!$next) {
            return response()->json(['status' => 'done', 'total' => count($qids), 'message' => 'All expeditions are synced.']);
        }

        return response()->json([
            'status' => 'ok',
            'total'  => count($qids),
            'next'   => route('sync.expedition.meta', ['id' => $next->id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 2 — Sync metadata + participants for one expedition
    // -------------------------------------------------------------------------
    public function syncExpeditionMeta(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(60);

        $expedition = Expedition::findOrFail($id);
        $entity     = $this->wikidata->getEntity($expedition->wikidata_qid);

        if (!$entity) {
            return response()->json(['status' => 'error', 'message' => 'Could not fetch from Wikidata.']);
        }

        $expedition->fill($this->wikidata->extractExpeditionData($entity))->save();

        // Fetch every participant entity first, then resolve all the labels
        // they need (birth/death place, occupations) in one batched request
        // instead of one Wikidata call per QID.
        $participants  = [];
        $qidsToResolve = [];

        foreach ($this->wikidata->extractParticipantQids($entity) as $qid) {
            $personEntity = $this->wikidata->getEntity($qid);
            if (!$personEntity) continue;

            $data = $this->wikidata->extractPersonData($personEntity);
            $participants[$qid] = $data;

            if ($data['_birth_place_qid']) $qidsToResolve[] = $data['_birth_place_qid'];
            if ($data['_death_place_qid']) $qidsToResolve[] = $data['_death_place_qid'];
            foreach ($data['_occupation_qids'] as $occQid) $qidsToResolve[] = $occQid;
        }

        $labelMap = $this->wikidata->getLabels($qidsToResolve);

        foreach ($participants as $qid => $data) {
            $data = $this->resolvePersonLabels($data, $labelMap);

            $person = Person::updateOrCreate(['wikidata_qid' => $qid], $data);
            $expedition->people()->syncWithoutDetaching([$person->id]);
        }

        $expedition->load('people');

        $itinerary = $this->wikidata->extractItinerary($entity);

        // ── FIX: wipe existing points HERE, before saving itinerary to state ──
        // This ensures stale Wikidata points are cleared on every resync,
        // regardless of whether state was pre-seeded by resyncExpedition().
        Point::where('expedition_id', $expedition->id)->delete();

        if (!empty($itinerary)) {
            DB::table('sync_state')->updateOrInsert(
                ['key' => 'itinerary_' . $id],
                ['value' => json_encode($itinerary)]
            );
        }

        $gbifIds = $this->wikidata->extractGbifOccurrenceIds($entity);
        if (!empty($gbifIds)) {
            DB::table('sync_state')->updateOrInsert(
                ['key' => 'gbif_ids_' . $id],
                ['value' => json_encode($gbifIds)]
            );
        }

        return response()->json([
            'status'     => 'ok',
            'step'       => 'meta',
            'expedition' => $expedition->wikidata_qid,
            'label'      => $expedition->label,
            'next'       => route('sync.expedition.points', ['id' => $id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 3 — Sync itinerary points one at a time
    // -------------------------------------------------------------------------
    public function syncExpeditionPoints(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(60);

        $expedition = Expedition::findOrFail($id);
        $stateKey   = 'itinerary_' . $id;

        $saved     = DB::table('sync_state')->where('key', $stateKey)->value('value');
        $itinerary = $saved ? json_decode($saved, true) : null;

        if ($itinerary === null) {
            // No state from meta step — fetch directly (e.g. called in isolation)
            $entity    = $this->wikidata->getEntity($expedition->wikidata_qid);
            $itinerary = $entity ? $this->wikidata->extractItinerary($entity) : [];

            // Safety net: clear any stale points if we got here without a prior meta step
            Point::where('expedition_id', $expedition->id)->delete();
        }

        if (empty($itinerary)) {
            DB::table('sync_state')->where('key', $stateKey)->delete();
            return response()->json([
                'status' => 'ok',
                'step'   => 'points_done',
                'next'   => route('sync.expedition.bionomia', ['id' => $id, 'token' => $request->token]),
            ]);
        }

        $pointData = array_shift($itinerary);

        DB::table('sync_state')->updateOrInsert(
            ['key' => $stateKey],
            ['value' => json_encode($itinerary)]
        );

        $place = $this->wikidata->getPlaceDetails($pointData['qid']);
        if ($place && $place['lat']) {
            Point::updateOrCreate(
                [
                    'expedition_id' => $expedition->id,
                    'wikidata_qid'  => $pointData['qid'],
                    'point_in_time' => $pointData['point_in_time'],
                    'start_time'    => $pointData['start_time'],
                ],
                [
                    'type'          => $pointData['type'],
                    'order'         => 0,
                    'end_time'      => $pointData['end_time'],
                    'name'          => $place['name'],
                    'description'   => $place['description'],
                    'image'         => $place['image'],
                    'lat'           => $place['lat'],
                    'lon'           => $place['lon'],
                ]
            );
        }

        $next = count($itinerary) > 0
            ? route('sync.expedition.points', ['id' => $id, 'token' => $request->token])
            : route('sync.expedition.bionomia', ['id' => $id, 'token' => $request->token]);

        return response()->json([
            'status'           => 'ok',
            'step'             => 'point',
            'processed'        => $pointData['qid'],
            'place_name'       => $place['name'] ?? null,
            'has_image'        => !empty($place['image']),
            'remaining_points' => count($itinerary),
            'next'             => $next,
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 4 — Sync GBIF specimens + finalise expedition
    // -------------------------------------------------------------------------
    public function syncExpeditionGbif(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(60);

        $expedition = Expedition::findOrFail($id);

        $saved  = DB::table('sync_state')->where('key', 'gbif_ids_' . $id)->value('value');
        $occIds = $saved ? json_decode($saved, true) : [];

        foreach ($occIds as $occId) {
            $raw = $this->gbif->getOccurrence($occId);
            if ($raw) $this->saveSpecimen($raw, $expedition->id);
        }

        $gbifSpecimens = $this->gbif->getOccurrencesByExpeditionQid($expedition->wikidata_qid);
        foreach ($gbifSpecimens as $raw) {
            try {
                $this->saveSpecimen($raw, $expedition->id);
            } catch (\Exception $e) {
                \Log::warning("GBIF specimen save failed (key={$raw['key']}): " . $e->getMessage());
            }
        }

        DB::table('sync_state')->where('key', 'gbif_ids_' . $id)->delete();

        return response()->json([
            'status'    => 'ok',
            'step'      => 'gbif',
            'gbif_count'=> count($gbifSpecimens),
            'next'      => route('sync.expedition.georeference', ['id' => $id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 5 — Sync coordinates from georeference.it (takes priority over
    // GBIF/Bionomia when it has a validated/suggested record), then finalise
    // the expedition (map build, search index, synced_at, institution stats).
    // -------------------------------------------------------------------------
    public function syncExpeditionGeoreference(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(90);

        $expedition    = Expedition::findOrFail($id);
        $stateKey      = 'georeference_cursor_' . $id;
        $budgetSeconds = 45; // leaves headroom under set_time_limit(90) for the finalise step below
        $startTime     = time();

        $lastId = (int) (DB::table('sync_state')->where('key', $stateKey)->value('value') ?? 0);

        $specimens = $expedition->specimens()
            ->whereNotNull('specimens.gbif_id')
            ->where('specimens.id', '>', $lastId)
            ->orderBy('specimens.id')
            ->limit(250)
            ->get();

        $updated         = 0;
        $lastProcessedId = $lastId;

        foreach ($specimens as $specimen) {
            if (time() - $startTime >= $budgetSeconds) break;

            if ($this->georeference->enrichSpecimen($specimen)) $updated++;
            $lastProcessedId = $specimen->id;
            usleep(220000); // ~270 req/min, under georeference.it's Expeditia allowance of 300 req/min
        }

        if ($lastProcessedId > $lastId) {
            DB::table('sync_state')->updateOrInsert(
                ['key' => $stateKey],
                ['value' => (string) $lastProcessedId]
            );
        }

        $hasMore = $expedition->specimens()
            ->whereNotNull('specimens.gbif_id')
            ->where('specimens.id', '>', $lastProcessedId)
            ->exists();

        if ($hasMore) {
            return response()->json([
                'status'  => 'ok',
                'step'    => 'georeference',
                'updated' => $updated,
                'next'    => route('sync.expedition.georeference', ['id' => $id, 'token' => $request->token]),
            ]);
        }

        // No more specimens left — clear cursor and finalise (moved from
        // syncExpeditionGbif so georeference always runs before the map/search rebuild).
        DB::table('sync_state')->where('key', $stateKey)->delete();

        $count = $this->mapBuilder->build($expedition);
        $this->search->indexExpedition($expedition);
        foreach ($expedition->people as $p) {
            $this->search->indexPerson($p);
        }
        $expedition->synced_at = now();
        $expedition->save();

        $this->institutions->refreshStats();

        $single = DB::table('sync_state')
            ->where('key', 'single_expedition_' . $id)
            ->exists();

        if ($single) {
            DB::table('sync_state')->where('key', 'single_expedition_' . $id)->delete();
            return response()->json([
                'status'    => 'done',
                'step'      => 'georeference',
                'map_items' => $count,
                'updated'   => $updated,
                'message'   => 'Expedition synced.',
            ]);
        }

        $nextExpedition = Expedition::whereNull('synced_at')->whereNull('retired_at')->first();

        if ($nextExpedition) {
            return response()->json([
                'status'    => 'ok',
                'step'      => 'georeference',
                'map_items' => $count,
                'updated'   => $updated,
                'next'      => route('sync.expedition.meta', ['id' => $nextExpedition->id, 'token' => $request->token]),
            ]);
        }

        return response()->json([
            'status'    => 'done',
            'step'      => 'georeference',
            'map_items' => $count,
            'updated'   => $updated,
            'message'   => 'All expeditions synced.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 5 — Sync Bionomia specimens, one page per request
    // -------------------------------------------------------------------------
    public function syncExpeditionBionomia(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(60);

        $expedition    = Expedition::findOrFail($id);
        $stateKey      = 'bionomia_done_' . $id;

        $doneJson      = DB::table('sync_state')->where('key', $stateKey)->value('value');
        $donePersonIds = $doneJson ? json_decode($doneJson, true) : [];

        $person = $expedition->people()
            ->whereNotIn('people.id', $donePersonIds ?: [0])
            ->first();

        if (!$person) {
            DB::table('sync_state')->where('key', $stateKey)->delete();

            return response()->json([
                'status' => 'ok',
                'step'   => 'bionomia_done',
                'next'   => route('sync.expedition.gbif-enrich', ['id' => $id, 'token' => $request->token]),
            ]);
        }

        $result = $this->bionomia->syncPersonSpecimensPage($person, $expedition);

        if ($result['done']) {
            $this->linkSpecimensToExpedition($person, $expedition);

            $donePersonIds[] = $person->id;
            DB::table('sync_state')->upsert(
                ['key' => $stateKey, 'value' => json_encode($donePersonIds)],
                ['key'],
                ['value']
            );
        }

        return response()->json([
            'status'     => 'ok',
            'step'       => 'bionomia',
            'person'     => $person->wikidata_qid,
            'skipped'    => $result['skipped'] ?? false,
            'page_done'  => $result['done'],
            'page_count' => $result['count'],
            'next'       => route('sync.expedition.bionomia', ['id' => $id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Step 5b — Backfill image/locality from GBIF for every specimen linked to
    // this expedition (Bionomia's JSON-LD doesn't carry photos). Previously
    // capped at 10 specimens per person; now drains everything via a cursor
    // across chained requests, same pattern as the georeference step.
    // -------------------------------------------------------------------------
    public function syncExpeditionGbifEnrich(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(90);

        $expedition = Expedition::findOrFail($id);
        $stateKey   = 'gbif_enrich_cursor_' . $id;
        $force      = $this->isForceRefresh($id);

        $lastId = (int) (DB::table('sync_state')->where('key', $stateKey)->value('value') ?? 0);

        $result = $this->enrichExpeditionSpecimens($expedition, budgetSeconds: 50, afterId: $lastId, limit: 300, force: $force);

        if ($result['lastProcessedId'] > $lastId) {
            DB::table('sync_state')->updateOrInsert(
                ['key' => $stateKey],
                ['value' => (string) $result['lastProcessedId']]
            );
        }

        $hasMore = $this->specimensNeedingGbifEnrich($expedition, $result['lastProcessedId'], $force)->exists();

        if ($hasMore) {
            return response()->json([
                'status'  => 'ok',
                'step'    => 'gbif_enrich',
                'updated' => $result['updated'],
                'next'    => route('sync.expedition.gbif-enrich', ['id' => $id, 'token' => $request->token]),
            ]);
        }

        DB::table('sync_state')->where('key', $stateKey)->delete();
        // Last consumer of the force-refresh flag in the chain — clear it here.
        DB::table('sync_state')->where('key', 'force_refresh_' . $id)->delete();

        return response()->json([
            'status'  => 'ok',
            'step'    => 'gbif_enrich',
            'updated' => $result['updated'],
            'next'    => route('sync.expedition.gbif', ['id' => $id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Refresh a single specimen from GBIF + georeference.it — lets a user
    // correct one record without resyncing the whole expedition (which can
    // mean thousands of specimens).
    // -------------------------------------------------------------------------
    public function refreshSpecimen(Request $request, string $gbifId)
    {
        $this->checkToken($request);

        $specimen = Specimen::where('gbif_id', $gbifId)->firstOrFail();

        $this->gbif->enrichSpecimen($specimen, force: true);
        $this->georeference->enrichSpecimen($specimen);
        $specimen->refresh();

        // Keep any map_items rows (list/map display cache) referencing this
        // specimen in sync with what we just fetched.
        MapItem::where('type', 'specimen')
            ->where('source_id', $specimen->id)
            ->update([
                'lat'   => $specimen->lat,
                'lon'   => $specimen->lon,
                'image' => $specimen->image,
                'name'  => $specimen->scientific_name,
            ]);

        return response()->json([
            'status'              => 'ok',
            'lat'                 => $specimen->lat,
            'lon'                 => $specimen->lon,
            'coord_source'        => $specimen->coord_source,
            'coord_status'        => $specimen->coord_status,
            'coord_uncertainty_m' => $specimen->coord_uncertainty_m,
            'image'               => $specimen->image,
            'locality'            => $specimen->locality,
            'verbatim_locality'   => $specimen->verbatim_locality,
            'higher_geography'    => $specimen->higher_geography,
            'state_province'      => $specimen->state_province,
            'county'              => $specimen->county,
            'municipality'        => $specimen->municipality,
            'scientific_name'     => $specimen->scientific_name,
            'family'              => $specimen->family,
            'kingdom'             => $specimen->kingdom,
            'institution_code'    => $specimen->institution_code,
            'collection_code'     => $specimen->collection_code,
            'catalog_number'      => $specimen->catalog_number,
            'recorded_by'         => $specimen->recorded_by,
            'identified_by'       => $specimen->identified_by,
            'type_status'         => $specimen->type_status,
            'country'             => $specimen->country,
            'country_code'        => $specimen->country_code,
        ]);
    }

    // -------------------------------------------------------------------------
    // Manual sync for a single person across all their expeditions
    // -------------------------------------------------------------------------
    public function syncPerson(Request $request, int $id)
    {
        $this->checkToken($request);
        set_time_limit(600);

        $person = Person::findOrFail($id);

        $entity = $this->wikidata->getEntity($person->wikidata_qid);
        if ($entity) {
            $data     = $this->wikidata->extractPersonData($entity);
            $labelMap = $this->wikidata->getLabels(array_filter([
                $data['_birth_place_qid'],
                $data['_death_place_qid'],
                ...$data['_occupation_qids'],
            ]));
            $data = $this->resolvePersonLabels($data, $labelMap);

            $person->fill($data)->save();
        }

        $totalItems = 0;

        foreach ($person->expeditions as $expedition) {
            $this->bionomia->syncAllPersonSpecimens($person, $expedition);
            $this->linkSpecimensToExpedition($person, $expedition);
            // Manual, user-triggered sync — always force a full metadata/coordinate refresh.
            $this->enrichExpeditionSpecimens($expedition, budgetSeconds: 60, force: true);
            $totalItems += $this->mapBuilder->build($expedition);
        }

        $this->search->indexPerson($person);
        foreach ($person->expeditions as $expedition) {
            $this->search->indexExpedition($expedition);
        }

        $this->institutions->refreshStats();

        return response()->json([
            'status'    => 'ok',
            'person'    => $person->wikidata_qid,
            'specimens' => Specimen::where('person_id', $person->id)->count(),
            'map_items' => $totalItems,
        ]);
    }

    // -------------------------------------------------------------------------
    // Force re-sync of an existing expedition by QID
    // -------------------------------------------------------------------------
    public function resyncExpedition(Request $request, string $qid)
    {
        $this->checkToken($request);

        $expedition = Expedition::where('wikidata_qid', $qid)->first();

        if (!$expedition) {
            return response()->json(['status' => 'error', 'message' => 'Expedition ' . $qid . ' not found.'], 404);
        }

        $expedition->synced_at = null;
        $expedition->save();

        // Full clean slate for resync — including Points (FIX: was missing before)
        Point::where('expedition_id', $expedition->id)->delete();
        \App\Models\ExpeditionOccurrence::where('expedition_id', $expedition->id)->delete();
        \App\Models\MapItem::where('expedition_id', $expedition->id)->delete();

        // Force fresh Bionomia re-fetch for all participants
        $expedition->people()->update(['bionomia_synced_at' => null]);

        DB::table('sync_state')->where('key', 'itinerary_' . $expedition->id)->delete();
        DB::table('sync_state')->where('key', 'gbif_ids_' . $expedition->id)->delete();
        DB::table('sync_state')->where('key', 'bionomia_done_' . $expedition->id)->delete();
        DB::table('sync_state')->where('key', 'like', 'bionomia_cursor_%')->delete();
        DB::table('sync_state')->where('key', 'gbif_enrich_cursor_' . $expedition->id)->delete();
        DB::table('sync_state')->where('key', 'georeference_cursor_' . $expedition->id)->delete();

        DB::table('sync_state')->updateOrInsert(
            ['key' => 'single_expedition_' . $expedition->id],
            ['value' => '1']
        );

        // User-triggered resync — refresh everything from GBIF regardless of
        // how "complete" it already looks, and relax the specimen-linking
        // date match (see linkSpecimensToExpedition). Cleared once the
        // gbif_enrich step for this expedition finishes.
        DB::table('sync_state')->updateOrInsert(
            ['key' => 'force_refresh_' . $expedition->id],
            ['value' => '1']
        );

        DB::table('sync_state')->updateOrInsert(
            ['key' => 'sync_next_url'],
            ['value' => route('sync.expedition.meta', ['id' => $expedition->id, 'token' => $request->token])]
        );

        return response()->json([
            'status' => 'ok',
            'next'   => route('sync.expedition.meta', ['id' => $expedition->id, 'token' => $request->token]),
        ]);
    }

    // -------------------------------------------------------------------------
    // Add and queue a new expedition by QID
    // -------------------------------------------------------------------------
    public function addExpedition(Request $request, string $qid)
    {
        $this->checkToken($request);

        if (!preg_match('/^Q\d+$/', $qid)) {
            return response()->json(['status' => 'error', 'message' => $qid . ' is not a valid Wikidata QID.'], 422);
        }

        $entity = $this->wikidata->getEntity($qid);
        if (!$entity) {
            return response()->json(['status' => 'error', 'message' => 'Could not find ' . $qid . ' on Wikidata.'], 404);
        }

        $expedition = Expedition::firstOrCreate(
            ['wikidata_qid' => $qid],
            ['label' => $entity['labels']['mul']['value'] ?? $entity['labels']['en']['value'] ?? $qid]
        );

        $expedition->synced_at = null;
        $expedition->save();

        DB::table('sync_state')->updateOrInsert(
            ['key' => 'sync_next_url'],
            ['value' => route('sync.expedition.meta', ['id' => $expedition->id])]
        );

        return response()->json([
            'status' => 'ok',
            'qid'    => $expedition->wikidata_qid,
            'label'  => $expedition->label,
        ]);
    }

    // -------------------------------------------------------------------------
    // Refresh institution stats + GBIF metadata (can be called standalone)
    // -------------------------------------------------------------------------
    public function syncInstitutions(Request $request)
    {
        $this->checkToken($request);

        $total   = $this->institutions->refreshStats();
        $updated = $this->institutions->refreshGbifMetadata(
            limit:     (int) $request->input('limit', 20),
            staleDays: (int) $request->input('stale_days', 30)
        );

        return response()->json([
            'status'           => 'ok',
            'total_institutions' => $total,
            'gbif_updated'     => $updated,
        ]);
    }

    // -------------------------------------------------------------------------
    // Cron entry point — chains through sync_state until time runs out
    // -------------------------------------------------------------------------
    public function cronSync(Request $request)
    {
        $this->checkToken($request);

        $startTime  = time();
        $maxSeconds = 300;
        $token      = $request->token;

        $saved = DB::table('sync_state')->where('key', 'sync_next_url')->value('value');
        $next  = $saved ?? route('sync.expeditions', ['token' => $token]);

        $steps = 0;

        while (time() - $startTime < $maxSeconds) {
            $url = $this->appendToken($next, $token);

            try {
                $response = Http::timeout(280)->get($url);
            } catch (\Exception $e) {
                DB::table('sync_state')->updateOrInsert(
                    ['key' => 'sync_next_url'],
                    ['value' => $next, 'updated_at' => now()]
                );
                return response()->json(['status' => 'error', 'message' => $e->getMessage(), 'steps' => $steps]);
            }

            if (!$response->ok()) {
                DB::table('sync_state')->updateOrInsert(
                    ['key' => 'sync_next_url'],
                    ['value' => $next, 'updated_at' => now()]
                );
                return response()->json(['status' => 'error', 'message' => 'HTTP ' . $response->status(), 'steps' => $steps, 'url' => $url]);
            }

            $data = $response->json();
            $steps++;

            if (empty($data['next'])) {
                DB::table('sync_state')->where('key', 'sync_next_url')->delete();
                return response()->json(['status' => 'done', 'steps' => $steps]);
            }

            $next = $data['next'];

            DB::table('sync_state')->updateOrInsert(
                ['key' => 'sync_next_url'],
                ['value' => $next, 'updated_at' => now()]
            );
        }

        return response()->json(['status' => 'paused', 'steps' => $steps, 'next' => $next]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fill in place_birth/place_death/occupation on person data extracted from
     * Wikidata, using a pre-resolved QID => label map (see getLabels()).
     */
    private function resolvePersonLabels(array $data, array $labelMap): array
    {
        if ($data['_birth_place_qid']) $data['place_birth'] = $labelMap[$data['_birth_place_qid']] ?? null;
        if ($data['_death_place_qid']) $data['place_death'] = $labelMap[$data['_death_place_qid']] ?? null;

        $occupations = [];
        foreach ($data['_occupation_qids'] as $occQid) {
            if (!empty($labelMap[$occQid])) $occupations[] = $labelMap[$occQid];
        }
        $data['occupation'] = implode(', ', $occupations);
        unset($data['_birth_place_qid'], $data['_death_place_qid'], $data['_occupation_qids']);

        return $data;
    }

    /**
     * Specimens linked to this expedition that still need a GBIF image/locality
     * backfill (Bionomia's JSON-LD doesn't carry either). When $force is true
     * (user-triggered resync), every specimen qualifies — not just the ones
     * that look incomplete, since GBIF's own record may have been corrected.
     */
    private function specimensNeedingGbifEnrich(Expedition $expedition, int $afterId = 0, bool $force = false)
    {
        $query = $expedition->specimens()
            ->whereNotNull('specimens.gbif_id')
            ->where('specimens.id', '>', $afterId);

        if (!$force) {
            $query->where(function ($q) {
                // Missing metadata GBIF can fill, OR a coordinate that only
                // came from Bionomia's mirrored copy — GBIF is more
                // authoritative than Bionomia for coordinates, so those
                // always get double-checked here too. Also catches stale
                // image URLs from before GBIF's "unsafe" cache proxy broke.
                $q->whereNull('specimens.image')
                  ->orWhereNull('specimens.locality')
                  ->orWhere('specimens.coord_source', 'bionomia')
                  ->orWhere('specimens.image', 'like', 'https://api.gbif.org/v1/image/unsafe/%');
            });
        }

        return $query->orderBy('specimens.id');
    }

    /**
     * Backfill image/locality from GBIF for specimens in this expedition, and
     * double-check any Bionomia-sourced coordinate against GBIF's own record
     * (GBIF is more authoritative than Bionomia's mirrored copy). Runs within
     * a time budget. Returns ['updated' => int, 'lastProcessedId' => int].
     */
    private function enrichExpeditionSpecimens(Expedition $expedition, int $budgetSeconds, int $afterId = 0, int $limit = 1000, bool $force = false): array
    {
        $specimens = $this->specimensNeedingGbifEnrich($expedition, $afterId, $force)->limit($limit)->get();

        $updated         = 0;
        $lastProcessedId = $afterId;
        $startTime       = time();

        foreach ($specimens as $specimen) {
            if (time() - $startTime >= $budgetSeconds) break;

            $hadImage    = $specimen->image;
            $hadLocality = $specimen->locality;
            $hadLat      = $specimen->lat;
            $hadLon      = $specimen->lon;
            $this->gbif->enrichSpecimen($specimen, $force);
            if ($specimen->image !== $hadImage || $specimen->locality !== $hadLocality
                || $specimen->lat != $hadLat || $specimen->lon != $hadLon) $updated++;

            $lastProcessedId = $specimen->id;
        }

        return ['updated' => $updated, 'lastProcessedId' => $lastProcessedId];
    }

    /**
     * A person can collect specimens throughout their whole life — only a
     * matching event_date (or the publisher's own eventID/parentEventID,
     * handled elsewhere in the GBIF discovery path) tells us a specimen
     * belongs to *this* expedition specifically. A specimen with no usable
     * date is correctly left unlinked; that Bionomia may not report an
     * event_date for many of a person's specimens is a Bionomia data gap,
     * not a reason to loosen this match.
     */
    private function linkSpecimensToExpedition(Person $person, Expedition $expedition): void
    {
        // No date info at all for this expedition — we have no way to tell
        // which (if any) of this person's specimens belong to it, so link
        // none via this date sweep. Without this guard, a missing start AND
        // end skipped the date filter entirely below, matching this person's
        // *entire* recorded history regardless of expedition.
        if (!$expedition->start_time && !$expedition->end_time) return;

        $query = Specimen::where('person_id', $person->id);

        if ($expedition->start_time || $expedition->end_time) {
            // Wikidata dates may be year-only, year-month, or a full date.
            // Expand partial precision to the earliest/latest calendar day
            // it could mean, so a bare "1960" start doesn't accidentally
            // exclude real dates later in that same year at the day-precision
            // comparison below (a specimen dated "1960-06-15" is not <= the
            // bare string "1960" — it needs to compare against "1960-12-31").
            $startFull = $expedition->start_time ? $this->expandToEarliestDate($expedition->start_time) : null;
            $endFull   = $expedition->end_time   ? $this->expandToLatestDate($expedition->end_time)     : null;

            // Many Wikidata expedition entries have a start date but no
            // recorded end date. Without a ceiling here, that means "matches
            // this person's entire collecting career from that day on" —
            // sweeping in decades of unrelated specimens. Fall back to a
            // generous but bounded window instead of leaving it open-ended.
            if (!$endFull && $startFull) {
                $endFull = date('Y-m-d', strtotime($startFull . ' +1 year'));
            }

            $startMonth = $startFull ? substr($startFull, 0, 7) : null;
            $endMonth   = $endFull   ? substr($endFull,   0, 7) : null;
            $startYear  = $startFull ? (int) substr($startFull, 0, 4)  : null;
            $endYear    = $endFull   ? (int) substr($endFull,   0, 4)  : null;

            $query->where(function($q) use ($startFull, $endFull, $startMonth, $endMonth, $startYear, $endYear) {
                $q->where(function($q2) use ($startFull, $endFull) {
                    $q2->whereRaw("LENGTH(event_date) >= 8");
                    if ($startFull) $q2->where('event_date', '>=', $startFull);
                    if ($endFull)   $q2->where('event_date', '<=', $endFull);
                });

                if ($startMonth && $endMonth) {
                    $q->orWhere(function($q2) use ($startMonth, $endMonth) {
                        $q2->whereRaw("LENGTH(event_date) = 7")
                           ->where('event_date', '>', $startMonth)
                           ->where('event_date', '<', $endMonth);
                    });
                }

                if ($startYear && $endYear && $endYear - $startYear >= 2) {
                    $q->orWhere(function($q2) use ($startYear, $endYear) {
                        $q2->whereRaw("LENGTH(event_date) = 4")
                           ->whereRaw("CAST(event_date AS UNSIGNED) > ?", [$startYear])
                           ->whereRaw("CAST(event_date AS UNSIGNED) < ?", [$endYear]);
                    });
                }
            });
        }

        foreach ($query->get() as $specimen) {
            $this->linkSpecimenToExpedition($specimen, $expedition);
        }
    }

    /**
     * Expand a Wikidata date (year-only "1960", year-month "1960-03", or a
     * full "1960-03-27") to the earliest calendar day that precision could
     * mean — used for start dates.
     */
    private function expandToEarliestDate(string $date): string
    {
        if (preg_match('/^\d{4}$/', $date))      return $date . '-01-01';
        if (preg_match('/^\d{4}-\d{2}$/', $date)) return $date . '-01';
        return $date;
    }

    /**
     * Expand a Wikidata date to the latest calendar day that precision
     * could mean — used for end dates.
     */
    private function expandToLatestDate(string $date): string
    {
        if (preg_match('/^\d{4}$/', $date)) return $date . '-12-31';
        if (preg_match('/^\d{4}-\d{2}$/', $date)) {
            $lastDay = (int) date('t', strtotime($date . '-01'));
            return sprintf('%s-%02d', $date, $lastDay);
        }
        return $date;
    }

    private function saveSpecimen(array $raw, int $expeditionId, ?int $personId = null): Specimen
    {
        $data = $this->gbif->normaliseOccurrence($raw);

        $existing = Specimen::where('gbif_id', $data['gbif_id'])->value('person_id');
        $data['person_id'] = $personId ?? $existing;

        // lat/lon (+ uncertainty, which describes that specific pair) handled
        // separately from the upsert below: GBIF sometimes has no coordinate,
        // and re-discovering this occurrence on a later sync must never
        // silently wipe a coordinate georeference.it already corrected.
        $newLat         = $data['lat'];
        $newLon         = $data['lon'];
        $newUncertainty = $data['coord_uncertainty_m'];
        unset($data['lat'], $data['lon'], $data['coord_uncertainty_m']);

        $specimen = Specimen::updateOrCreate(['gbif_id' => $data['gbif_id']], $data);

        if ($newLat !== null && $newLon !== null && $specimen->coord_source !== 'georeference') {
            $specimen->lat                 = $newLat;
            $specimen->lon                 = $newLon;
            $specimen->coord_source        = 'gbif';
            $specimen->coord_uncertainty_m = $newUncertainty;
            $specimen->save();
        }

        $this->linkSpecimenToExpedition($specimen, Expedition::find($expeditionId));

        return $specimen;
    }

    private function linkSpecimenToExpedition(Specimen $specimen, ?Expedition $expedition): void
    {
        if (!$expedition) return;

        ExpeditionOccurrence::firstOrCreate(
            ['expedition_id' => $expedition->id, 'specimen_id' => $specimen->id],
            ['person_id'     => $specimen->person_id]
        );
    }

    private function appendToken(string $url, string $token): string
    {
        $parsed = parse_url($url);
        $query  = [];
        if (!empty($parsed['query'])) parse_str($parsed['query'], $query);
        $query['token']  = $token;
        $parsed['query'] = http_build_query($query);

        return $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'] . '?' . $parsed['query'];
    }

    private function checkToken(Request $request): void
    {
        $validToken = config('sync.token');
        if ($validToken && $request->token !== $validToken) {
            abort(403, 'Invalid sync token.');
        }
    }

    private function isForceRefresh(int $expeditionId): bool
    {
        return DB::table('sync_state')->where('key', 'force_refresh_' . $expeditionId)->exists();
    }
}
