<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Expedition;
use App\Models\Person;
use App\Models\Specimen;

class BionomiaService
{
    const API_URL    = 'https://api.bionomia.net/';
    const USER_AGENT = 'Expeditia/2.0 (https://expeditia.info)';

    // -------------------------------------------------------------------------
    // Cron use — processes ONE page per HTTP request.
    //
    // On the first call (no cursor saved), fetches the first page and checks
    // dateModified. If Bionomia has no changes since the last sync, skips
    // immediately without processing any specimens.
    //
    // Returns:
    //   ['done' => true,  'skipped' => true,  'count' => 0]  — no changes
    //   ['done' => false, 'skipped' => false,  'count' => int] — more pages remain
    //   ['done' => true,  'skipped' => false,  'count' => int] — last page done
    // -------------------------------------------------------------------------
    public function syncPersonSpecimensPage(Person $person, Expedition $expedition): array
    {
        if (!$person->bionomia_id) {
            return ['done' => true, 'skipped' => true, 'count' => 0];
        }

        $stateKey    = 'bionomia_cursor_' . $person->wikidata_qid;
        $savedCursor = \DB::table('sync_state')->where('key', $stateKey)->value('value');
        $isFirstPage = $savedCursor === null;

        $url = $savedCursor ?? (self::API_URL . $person->bionomia_id . '/specimens.jsonld');

        // Small delay to respect Bionomia rate limits
        usleep(500000); // 0.5s

        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(25)
            ->get($url);

        if (!$response->ok()) {
            \Log::warning("Bionomia fetch failed for {$person->wikidata_qid} (bionomia_id: {$person->bionomia_id}, url: {$url}): HTTP " . $response->status());
            \DB::table('sync_state')->where('key', $stateKey)->delete();
            return ['done' => true, 'skipped' => false, 'count' => 0];
        }

        $data = $response->json();

        // On the first page, check if there's anything new to process
        if ($isFirstPage && $this->isUpToDate($data, $person)) {
            return ['done' => true, 'skipped' => true, 'count' => 0];
        }

        $count      = $this->processRecordedSpecimens($data, $person, $expedition);
        $nextCursor = $this->sanitizePageUrl($data['as:next'] ?? null);

        if ($nextCursor) {
            \DB::table('sync_state')->updateOrInsert(
                ['key' => $stateKey],
                ['value' => $nextCursor]
            );
            return ['done' => false, 'skipped' => false, 'count' => $count];
        }

        // Last page — clear cursor and mark person as synced
        \DB::table('sync_state')->where('key', $stateKey)->delete();
        $person->bionomia_synced_at = now();
        $person->save();

        return ['done' => true, 'skipped' => false, 'count' => $count];
    }

    // -------------------------------------------------------------------------
    // Manual / one-shot use (e.g. /sync/person/{id}).
    // Follows all cursor pages in a single PHP execution.
    // -------------------------------------------------------------------------
    public function syncAllPersonSpecimens(Person $person, Expedition $expedition): int
    {
        if (!$person->bionomia_id) {
            return 0;
        }

        $url   = self::API_URL . $person->bionomia_id . '/specimens.jsonld';
        $total = 0;
        $first = true;

        do {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(25)
                ->get($url);

            if (!$response->ok()) {
                \Log::warning("Bionomia fetch failed for {$person->wikidata_qid} (bionomia_id: {$person->bionomia_id}, url: {$url}): HTTP " . $response->status());
                break;
            }

            $data = $response->json();

            if ($first && $this->isUpToDate($data, $person)) {
                return 0;
            }

            $total += $this->processRecordedSpecimens($data, $person, $expedition);
            $url    = $this->sanitizePageUrl($data['as:next'] ?? null);
            $first  = false;

        } while ($url);

        $person->bionomia_synced_at = now();
        $person->save();

        return $total;
    }

    // -------------------------------------------------------------------------
    // Private — check if Bionomia has been updated since our last sync
    // -------------------------------------------------------------------------

    private function isUpToDate(array $data, Person $person): bool
    {
        if (!$person->bionomia_synced_at) return false;

        $dateModified = $data['dateModified'] ?? null;
        if (!$dateModified) return false;

        return \Carbon\Carbon::parse($dateModified)->lte($person->bionomia_synced_at);
    }

    // -------------------------------------------------------------------------
    // Private — process @reverse.recorded[] from one JSON-LD page
    // We only ingest "recorded" specimens: the people who physically collected
    // them during the expedition. "identified" are post-hoc museum attributions.
    // -------------------------------------------------------------------------

    private function processRecordedSpecimens(array $data, Person $person, Expedition $expedition): int
    {
        $recorded = $data['@reverse']['recorded'] ?? [];

        // A single specimen may be returned as an object instead of a one-item array
        if (isset($recorded['@type'])) {
            $recorded = [$recorded];
        }

        $count = 0;
        foreach ($recorded as $row) {
            if ($this->saveSpecimenRow($row, $person, $expedition)) {
                $count++;
            }
        }

        return $count;
    }

    // -------------------------------------------------------------------------
    // Private — upsert one specimen row
    // gbifID is extracted from @id: https://bionomia.net/occurrence/1234567890
    // -------------------------------------------------------------------------

    private function saveSpecimenRow(array $row, Person $person, Expedition $expedition): bool
    {
        $gbifId = $this->extractOccurrenceId($row['@id'] ?? null)
               ?? $this->extractOccurrenceId($row['sameAs'] ?? null);

        if (!$gbifId) return false;

        try {
            $specimen = Specimen::updateOrCreate(
                ['gbif_id' => $gbifId],
                [
                    'person_id'        => $person->id,
                    'expedition_id'    => $expedition->id,
                    'action'           => 'recorded',
                    'dataset_key'      => $row['datasetKey']        ?? null,
                    'occurrence_id'    => $row['occurrenceID']      ?? null,
                    'basis_of_record'  => $row['basisOfRecord']     ?? null,
                    'license'          => $row['license']           ?? null,
                    'scientific_name'  => $row['scientificName']    ?? null,
                    'family'           => $row['family']            ?? null,
                    'kingdom'          => $row['kingdom']           ?? null,
                    'type_status'      => $row['typeStatus']        ?? null,
                    'recorded_by'      => $row['recordedBy']        ?? null,
                    'recorded_by_id'   => $row['recordedByID']      ?? null,
                    'identified_by'    => $row['identifiedBy']      ?? null,
                    'identified_by_id' => $row['identifiedByID']    ?? null,
                    'institution_code' => $row['institutionCode']   ?? null,
                    'collection_code'  => $row['collectionCode']    ?? null,
                    'catalog_number'   => $row['catalogNumber']     ?? null,
                    'date_identified'  => $row['dateIdentified']    ?? null,
                    'event_date'       => $this->cleanDate($row['eventDate'] ?? null),
                    'country'          => $row['country']           ?? null,
                    'country_code'     => $row['countryCode']       ?? null,
                    'year'             => $this->nullIfEmpty($row['year']             ?? null),
                    'gbif_url'         => 'https://www.gbif.org/occurrence/' . $gbifId,
                ]
            );

            // lat/lon are handled separately from the upsert above: Bionomia
            // often has no coordinate at all (null), and re-syncing a person
            // must never silently wipe a coordinate GBIF or georeference.it
            // already provided.
            $newLat = $this->nullIfEmpty($row['decimalLatitude']  ?? null);
            $newLon = $this->nullIfEmpty($row['decimalLongitude'] ?? null);

            if ($newLat !== null && $newLon !== null && $specimen->coord_source !== 'georeference') {
                $specimen->lat                 = $newLat;
                $specimen->lon                 = $newLon;
                $specimen->coord_source        = 'bionomia';
                // Bionomia's JSON-LD doesn't expose coordinateUncertaintyInMeters
                // at all — leave unknown until GBIF or georeference.it checks it.
                $specimen->coord_uncertainty_m = null;
                $specimen->save();
            }

            return true;
        } catch (\Exception $e) {
            \Log::warning("Specimen import failed: {$gbifId} — " . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Bionomia's own "as:next" link sometimes comes back with a malformed
     * page value (observed: "?page=2+2" instead of "?page=2" — looks like an
     * unevaluated template expression on their end). Keep only the leading
     * digits so we follow a well-formed URL instead of whatever they sent.
     */
    private function sanitizePageUrl(?string $url): ?string
    {
        if (!$url) return null;
        return preg_replace('/([?&]page=)(\d+)[^&]*/', '$1$2', $url);
    }

    /**
     * Extract a numeric GBIF occurrence ID from a Bionomia or GBIF URI.
     * Handles:
     *   https://bionomia.net/occurrence/1234567890
     *   https://gbif.org/occurrence/1234567890
     *   plain numeric string
     */
    private function extractOccurrenceId(?string $uri): ?string
    {
        if (!$uri) return null;
        if (preg_match('/\/occurrence\/(\d+)/', $uri, $m)) return $m[1];
        if (is_numeric($uri)) return $uri;
        return null;
    }

    private function cleanDate(?string $date): ?string
    {
        if (!$date || $date === '?' || $date === '') return null;
        if (str_contains($date, '\\')) $date = explode('\\', $date)[0];
        if (str_contains($date, '/'))  $date = explode('/', $date)[0];
        if (str_contains($date, 'T'))  $date = explode('T', $date)[0];
        if (strlen($date) <= 4) return null;
        return $date;
    }

    private function nullIfEmpty(mixed $value): mixed
    {
        return ($value === '' || $value === null || $value === '?' || $value === 'NA') ? null : $value;
    }
}
