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

    // Seconds one import request may spend writing rows before it hands the
    // rest to the next request. Well under the controller's set_time_limit.
    public int $importBudgetSeconds = 35;
    public int $importBatchRows     = 400;

    // -------------------------------------------------------------------------
    // Cron use — bounded work per HTTP request, resumable between requests.
    //
    // Bionomia's paginated JSON-LD is unusable from a server (Cloudflare serves
    // a "Just a moment..." challenge, HTTP 403, to any URL with ?page=N), so we
    // use the CSV export instead. It can't be paged or resumed with Range, but
    // it *is* fast to download (~15 MB / 45k rows in ~20 s); what's slow is
    // upserting that many rows. So the work is split in two phases:
    //
    //   1. download — stream the whole CSV to storage/app/bionomia/{qid}.csv
    //                 (one request; skipped when dateModified says no changes)
    //   2. import   — read the file from a saved byte offset, bulk-upsert in
    //                 batches until the time budget is spent, save the new
    //                 offset, and return done=false so the chain calls again.
    //
    // Returns:
    //   ['done' => true,  'skipped' => true,  'count' => 0]   — no changes / nothing to do
    //   ['done' => false, 'skipped' => false, 'count' => int] — more work remains
    //   ['done' => true,  'skipped' => false, 'count' => int] — import finished
    // -------------------------------------------------------------------------
    public function syncPersonSpecimensPage(Person $person, Expedition $expedition): array
    {
        if (!$person->bionomia_id) {
            return ['done' => true, 'skipped' => true, 'count' => 0];
        }

        $stateKey = 'bionomia_cursor_' . $person->wikidata_qid;
        $path     = $this->csvPath($person);
        $saved    = \DB::table('sync_state')->where('key', $stateKey)->value('value');

        // Cursor is a byte offset into the downloaded CSV. Anything else (no
        // cursor, a leftover JSON-LD URL from the old implementation, or a
        // missing file) means we start from the download phase.
        $offset = ($saved !== null && ctype_digit((string) $saved) && is_file($path)) ? (int) $saved : null;

        if ($offset === null) {
            return $this->downloadPhase($person, $path, $stateKey);
        }

        return $this->importPhase($person, $path, $stateKey, $offset);
    }

    // -------------------------------------------------------------------------
    // Manual / one-shot use (e.g. /sync/person/{id}) — runs both phases back
    // to back in a single PHP execution (caller sets a generous time limit).
    // -------------------------------------------------------------------------
    public function syncAllPersonSpecimens(Person $person, Expedition $expedition): int
    {
        $total = 0;

        do {
            $result = $this->syncPersonSpecimensPage($person, $expedition);
            $total += $result['count'];
        } while (!$result['done']);

        return $total;
    }

    // -------------------------------------------------------------------------
    // Phase 1 — freshness check + CSV download
    // -------------------------------------------------------------------------

    private function downloadPhase(Person $person, string $path, string $stateKey): array
    {
        // Page 1 of the JSON-LD (no ?page= so Cloudflare lets it through) is
        // only used for dateModified: skip the whole download if Bionomia has
        // nothing new for this person. If it fails, just download anyway.
        if ($person->bionomia_synced_at) {
            $meta = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(25)
                ->get(self::API_URL . $person->bionomia_id . '/specimens.jsonld');

            if ($meta->ok() && is_array($meta->json()) && $this->isUpToDate($meta->json(), $person)) {
                return ['done' => true, 'skipped' => true, 'count' => 0];
            }
        }

        // Small delay to respect Bionomia rate limits
        usleep(500000); // 0.5s

        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        // Download to a temp name and rename on success, so a half-written
        // file is never mistaken for a complete one by a concurrent request.
        $tmp = $path . '.part';
        $url = 'https://bionomia.net/' . $person->bionomia_id . '/specimens.csv';

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(150)
                ->sink($tmp)
                ->get($url);
            $ok = $response->ok();
        } catch (\Throwable $e) {
            $ok = false;
            \Log::warning("Bionomia CSV download error for {$person->wikidata_qid}: " . $e->getMessage());
        }

        // A Cloudflare challenge page is HTML with a 403, but check the header
        // row too in case an HTML page ever comes back as a 200.
        if (!$ok || !$this->hasCsvHeader($tmp)) {
            // Include the start of what we actually got: a 200 that isn't a
            // CSV (e.g. a Cloudflare interstitial) is otherwise undiagnosable.
            $got = is_file($tmp)
                ? 'size=' . filesize($tmp) . ' starts=' . json_encode(substr((string) file_get_contents($tmp, false, null, 0, 200), 0, 200))
                : 'no file';
            \Log::warning("Bionomia CSV fetch failed for {$person->wikidata_qid} (bionomia_id: {$person->bionomia_id}, url: {$url}): HTTP " . (isset($response) ? $response->status() : 'n/a') . " — {$got}");
            @unlink($tmp);
            \DB::table('sync_state')->where('key', $stateKey)->delete();
            return ['done' => true, 'skipped' => false, 'count' => 0];
        }

        rename($tmp, $path);
        \DB::table('sync_state')->updateOrInsert(['key' => $stateKey], ['value' => '0']);

        // Download is its own request; the import starts on the next call.
        return ['done' => false, 'skipped' => false, 'count' => 0];
    }

    // -------------------------------------------------------------------------
    // Phase 2 — resumable bulk import from the downloaded CSV
    // -------------------------------------------------------------------------

    private function importPhase(Person $person, string $path, string $stateKey, int $offset): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) {
            \DB::table('sync_state')->where('key', $stateKey)->delete();
            return ['done' => true, 'skipped' => false, 'count' => 0];
        }

        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($offset > 0) fseek($fh, $offset);

        $start = time();
        $count = 0;
        $batch = [];
        $eof   = false;

        while (true) {
            $line = fgetcsv($fh, 0, ',', '"', '');

            if ($line === false) {
                $eof = true;
                break;
            }
            if ($line === [null] || count($line) !== count($header)) continue; // blank / malformed row

            $row = array_combine($header, $line);
            // Same scope as before: only specimens the person physically
            // collected; "identified" rows are post-hoc museum attributions.
            if (($row['action'] ?? '') !== 'recorded') continue;

            $batch[] = $row;

            if (count($batch) >= $this->importBatchRows) {
                $count += $this->saveSpecimenBatch($batch, $person);
                $batch  = [];

                // The offset is only advanced after a batch is committed, so a
                // crash re-imports at most one batch (the upsert is idempotent).
                $offset = ftell($fh);
                \DB::table('sync_state')->where('key', $stateKey)->update(['value' => (string) $offset]);

                if (time() - $start >= $this->importBudgetSeconds) break;
            }
        }

        if ($batch) $count += $this->saveSpecimenBatch($batch, $person);
        fclose($fh);

        if (!$eof) {
            return ['done' => false, 'skipped' => false, 'count' => $count];
        }

        // Whole file imported — clean up and mark the person as synced
        @unlink($path);
        \DB::table('sync_state')->where('key', $stateKey)->delete();
        $person->bionomia_synced_at = now();
        $person->save();

        return ['done' => true, 'skipped' => false, 'count' => $count];
    }

    private function csvPath(Person $person): string
    {
        return storage_path('app/bionomia/' . preg_replace('/[^A-Za-z0-9_-]/', '', $person->wikidata_qid) . '.csv');
    }

    private function hasCsvHeader(string $file): bool
    {
        if (!is_file($file)) return false;
        $fh   = fopen($file, 'rb');
        $head = $fh ? fread($fh, 64) : '';
        if ($fh) fclose($fh);
        return str_starts_with($head, 'action,gbifID');
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
    // Private — bulk upsert a batch of CSV rows (already filtered to "recorded").
    // We only ingest "recorded" specimens: the people who physically collected
    // them during the expedition. "identified" are post-hoc museum attributions.
    //
    // One SELECT + at most two multi-row upserts per batch, instead of 2-3
    // queries per specimen — this is what keeps a 40k-row collector inside a
    // few short requests.
    // -------------------------------------------------------------------------

    private function saveSpecimenBatch(array $rows, Person $person): int
    {
        $now     = now()->toDateTimeString();
        $mapped  = [];
        $newCoord = [];

        foreach ($rows as $row) {
            $gbifId = $this->extractOccurrenceId($row['gbifID'] ?? null);
            if (!$gbifId) continue;

            $mapped[$gbifId] = [
                'gbif_id'          => $gbifId,
                'person_id'        => $person->id,
                'action'           => 'recorded',
                'dataset_key'      => $this->nullIfEmpty($row['datasetKey']      ?? null),
                'occurrence_id'    => $this->nullIfEmpty($row['occurrenceID']    ?? null),
                'basis_of_record'  => $this->nullIfEmpty($row['basisOfRecord']   ?? null),
                'license'          => $this->nullIfEmpty($row['license']         ?? null),
                'scientific_name'  => $this->nullIfEmpty($row['scientificName']  ?? null),
                'family'           => $this->nullIfEmpty($row['family']          ?? null),
                'kingdom'          => $this->nullIfEmpty($row['kingdom']         ?? null),
                'type_status'      => $this->nullIfEmpty($row['typeStatus']      ?? null),
                'recorded_by'      => $this->nullIfEmpty($row['recordedBy']      ?? null),
                'recorded_by_id'   => $this->nullIfEmpty($row['recordedByID']    ?? null),
                'identified_by'    => $this->nullIfEmpty($row['identifiedBy']    ?? null),
                'identified_by_id' => $this->nullIfEmpty($row['identifiedByID']  ?? null),
                'institution_code' => $this->nullIfEmpty($row['institutionCode'] ?? null),
                'collection_code'  => $this->nullIfEmpty($row['collectionCode']  ?? null),
                'catalog_number'   => $this->nullIfEmpty($row['catalogNumber']   ?? null),
                'date_identified'  => $this->nullIfEmpty($row['dateIdentified']  ?? null),
                'event_date'       => $this->cleanDate($row['eventDate'] ?? null),
                'country'          => $this->nullIfEmpty($row['country']         ?? null),
                'country_code'     => $this->nullIfEmpty($row['countryCode']     ?? null),
                'year'             => $this->nullIfEmpty($row['year']            ?? null),
                'gbif_url'         => 'https://www.gbif.org/occurrence/' . $gbifId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            $lat = $this->nullIfEmpty($row['decimalLatitude']  ?? null);
            $lon = $this->nullIfEmpty($row['decimalLongitude'] ?? null);
            if ($lat !== null && $lon !== null) $newCoord[$gbifId] = [$lat, $lon];
        }

        if (!$mapped) return 0;

        // lat/lon are kept out of the plain upsert: Bionomia often has no
        // coordinate at all (null), and re-syncing a person must never
        // silently wipe a coordinate GBIF or georeference.it already
        // provided — nor overwrite one georeference.it corrected.
        $existingSource = Specimen::whereIn('gbif_id', array_keys($mapped))->pluck('coord_source', 'gbif_id');

        $plain = [];
        $withCoord = [];
        foreach ($mapped as $gbifId => $data) {
            if (isset($newCoord[$gbifId]) && ($existingSource[$gbifId] ?? null) !== 'georeference') {
                [$lat, $lon] = $newCoord[$gbifId];
                $withCoord[] = $data + [
                    'lat'                 => $lat,
                    'lon'                 => $lon,
                    'coord_source'        => 'bionomia',
                    // Bionomia doesn't expose coordinateUncertaintyInMeters
                    // — leave unknown until GBIF or georeference.it checks it.
                    'coord_uncertainty_m' => null,
                ];
            } else {
                $plain[] = $data;
            }
        }

        // created_at is deliberately not in the update list: it keeps the
        // original insert time on rows that already exist.
        $baseCols = array_values(array_diff(array_keys(reset($mapped)), ['gbif_id', 'created_at']));

        try {
            if ($plain)     \DB::table('specimens')->upsert($plain, ['gbif_id'], $baseCols);
            if ($withCoord) \DB::table('specimens')->upsert(
                $withCoord, ['gbif_id'], array_merge($baseCols, ['lat', 'lon', 'coord_source', 'coord_uncertainty_m'])
            );
        } catch (\Exception $e) {
            \Log::warning("Specimen batch import failed for {$person->wikidata_qid}: " . $e->getMessage());
            return 0;
        }

        return count($mapped);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract a numeric GBIF occurrence ID from a Bionomia or GBIF URI.
     * Handles:
     *   https://bionomia.net/occurrence/1234567890
     *   https://gbif.org/occurrence/1234567890
     *   plain numeric string (the CSV's gbifID column)
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
