<?php

namespace App\Services;

use App\Models\Institution;
use Illuminate\Support\Facades\DB;

class InstitutionService
{
    public function __construct(protected GbifService $gbif) {}

    // -------------------------------------------------------------------------
    // Refresh pre-computed stats for all institutions that appear in specimens.
    // Called after a sync cycle and from the manual sync route.
    // -------------------------------------------------------------------------
    public function refreshStats(): int
    {
        $rows = DB::table('specimens')
            ->join('expedition_occurrences', 'specimens.id', '=', 'expedition_occurrences.specimen_id')
            ->join('expeditions', 'expedition_occurrences.expedition_id', '=', 'expeditions.id')
            ->whereNotNull('specimens.institution_code')
            ->where('specimens.institution_code', '!=', '')
            ->whereNull('expeditions.retired_at')
            ->select(
                'specimens.institution_code as code',
                DB::raw('COUNT(DISTINCT specimens.id) as specimens_count'),
                DB::raw('COUNT(DISTINCT expedition_occurrences.expedition_id) as expeditions_count'),
                DB::raw('COUNT(DISTINCT CASE WHEN specimens.image IS NOT NULL THEN specimens.id END) as images_count'),
            )
            ->groupBy('specimens.institution_code')
            ->get();

        foreach ($rows as $row) {
            Institution::updateOrCreate(
                ['code' => $row->code],
                [
                    'specimens_count'   => $row->specimens_count,
                    'expeditions_count' => $row->expeditions_count,
                    'images_count'      => $row->images_count,
                ]
            );
        }

        // Remove institutions that no longer have any specimens
        $activeCodes = $rows->pluck('code')->all();
        Institution::whereNotIn('code', $activeCodes)->delete();

        return $rows->count();
    }

    // -------------------------------------------------------------------------
    // Refresh GBIF metadata (name, country, etc.) for institutions that
    // either have never been synced or were synced more than $days ago.
    // Returns number of institutions updated.
    // -------------------------------------------------------------------------
    public function refreshGbifMetadata(int $limit = 20, int $staleDays = 30): int
    {
        $stale = Institution::where(function ($q) use ($staleDays) {
            $q->whereNull('gbif_synced_at')
              ->orWhere('gbif_synced_at', '<', now()->subDays($staleDays));
        })
        ->whereNull('name')                     // prioritise unknowns first
        ->orWhere(function ($q) use ($staleDays) {
            $q->where('gbif_synced_at', '<', now()->subDays($staleDays));
        })
        ->orderByRaw('gbif_synced_at IS NOT NULL, gbif_synced_at ASC') // NULLs first
        ->limit($limit)
        ->get();

        $updated = 0;
        foreach ($stale as $institution) {
            $info = $this->gbif->getInstitutionInfo($institution->code);
            $institution->fill([
                'name'          => $info['name']     ?? $institution->name,
                'country'       => $info['country']  ?? $institution->country,
                'city'          => $info['city']      ?? $institution->city,
                'homepage'      => $info['homepage']  ?? $institution->homepage,
                'gbif_key'      => $info['gbif_key']  ?? $institution->gbif_key,
                'gbif_synced_at'=> now(),
            ])->save();
            $updated++;
        }

        return $updated;
    }

    // -------------------------------------------------------------------------
    // Link a Wikidata QID (from P664 on an expedition) to an institution by
    // code, when we can match them via GBIF GRSciColl.
    // This is best-effort — mismatches are silently skipped.
    // -------------------------------------------------------------------------
    public function linkWikidataQid(string $code, string $qid): void
    {
        Institution::where('code', $code)
            ->whereNull('wikidata_qid')
            ->update(['wikidata_qid' => $qid]);
    }
}
