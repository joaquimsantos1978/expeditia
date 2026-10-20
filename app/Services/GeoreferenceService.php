<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use App\Models\Specimen;

class GeoreferenceService
{
    const API_URL    = 'https://georeference.it/api/v1/';
    const USER_AGENT = 'Expeditia/2.0 (https://expeditia.info)';

    // georef_status values worth trusting over what GBIF/Bionomia gave us.
    // 'gbif_georeferenced' just mirrors GBIF's own coordinate (no correction),
    // 'ungeoreferenced' has no coordinate at all — neither is useful here.
    const ACCEPTED_STATUSES = ['validated', 'has_suggestion'];

    /**
     * Look up a single occurrence by its GBIF numeric key (specimens.gbif_id).
     * Note: georeference.it's "occurrenceID" is the GBIF key, not the Darwin
     * Core occurrenceID URN stored in specimens.occurrence_id.
     */
    public function lookupByGbifId(string $gbifId): ?array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->connectTimeout(5)
                ->timeout(15)
                ->get(self::API_URL . 'occurrences/' . $gbifId);
        } catch (ConnectionException $e) {
            // Can't reach georeference.it at all (DNS, firewall, down). This
            // is enrichment, not core data — never let it 500 the sync step.
            if (++$this->consecutiveFailures === self::UNAVAILABLE_AFTER) {
                \Log::warning('georeference.it unreachable, skipping it for this step: ' . $e->getMessage());
            }
            return null;
        }

        $this->consecutiveFailures = 0;

        return $response->ok() ? $response->json() : null;
    }

    // How many GBIF keys go in one batch request. Matches the cap in
    // georeference.it's OccurrenceController (MAX_OCCURRENCE_IDS).
    const BATCH_SIZE = 100;

    // Set when the server turns out to predate the occurrenceIDs filter, so the
    // remaining chunks of THIS request go straight to per-specimen lookups.
    // Deliberately kept in memory only: nothing from georeference.it is cached
    // between requests, since citizens' edits there must show up immediately.
    private bool $batchSupported = true;

    /**
     * Look up many occurrences in one request via the occurrenceIDs filter.
     * Returns ['k<gbifId>' => raw record, ...] — ids georeference.it doesn't
     * know are simply absent (the "k" prefix keeps 32-bit PHP from coercing
     * big numeric keys). Returns null when the batch path can't be used (older
     * server without the filter, or any failure): callers fall back to
     * lookupByGbifId() per specimen, and should check isUnavailable() first.
     */
    public function lookupMany(array $gbifIds): ?array
    {
        $gbifIds = array_values(array_unique(array_map('strval', array_filter($gbifIds))));
        if (!$gbifIds) return [];
        if (!$this->batchSupported) return null;

        try {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->connectTimeout(5)
                ->timeout(30)
                ->get(self::API_URL . 'occurrences', [
                    'occurrenceIDs' => implode('|', $gbifIds),
                    'perPage'       => count($gbifIds),
                ]);
        } catch (ConnectionException $e) {
            if (++$this->consecutiveFailures === self::UNAVAILABLE_AFTER) {
                \Log::warning('georeference.it unreachable, skipping it for this step: ' . $e->getMessage());
            }
            return null;
        }

        $this->consecutiveFailures = 0;

        $json = $response->ok() ? $response->json() : null;
        if (!is_array($json) || !isset($json['data'], $json['meta']['total'])) return null;

        // A server that ignores the unknown filter answers with an arbitrary
        // page of *all* its occurrences. A working filter can never match more
        // records than ids we asked for — so a bigger total means "unsupported".
        if ((int) $json['meta']['total'] > count($gbifIds)) {
            $this->batchSupported = false;
            return null;
        }

        $records = [];
        foreach ($json['data'] as $row) {
            if (isset($row['occurrenceID'])) $records['k' . $row['occurrenceID']] = $row;
        }
        return $records;
    }
    // A few failures in a row means the service itself is down (not one bad
    // record), so callers stop hammering it and move on.
    const UNAVAILABLE_AFTER = 3;

    private int $consecutiveFailures = 0;

    public function isUnavailable(): bool
    {
        return $this->consecutiveFailures >= self::UNAVAILABLE_AFTER;
    }

    /**
     * Normalise a raw georeference.it record. Returns null when the status
     * isn't trustworthy enough or coordinates are missing.
     */
    public function normaliseOccurrence(array $raw): ?array
    {
        $status = $raw['georef_status'] ?? null;
        if (!in_array($status, self::ACCEPTED_STATUSES, true)) return null;

        $lat = $raw['decimalLatitude']  ?? null;
        $lon = $raw['decimalLongitude'] ?? null;
        if ($lat === null || $lon === null) return null;

        return [
            'lat'                  => $lat,
            'lon'                  => $lon,
            'coord_source'         => 'georeference',
            'coord_status'         => $status,
            'coord_uncertainty_m'  => $raw['coordinateUncertaintyInMeters'] ?? null,
        ];
    }

    /**
     * Overwrite a specimen's coordinates when georeference.it has a
     * validated/suggested record — georeference.it takes priority over
     * whatever GBIF/Bionomia already put there.
     */
    public function enrichSpecimen(Specimen $specimen): bool
    {
        if (!$specimen->gbif_id) return false;

        return $this->applyRecord($specimen, $this->lookupByGbifId($specimen->gbif_id));
    }

    /**
     * Apply an already-fetched georeference.it record (null = no record) to a
     * specimen. Same rules as enrichSpecimen(); split out so batch lookups can
     * reuse them.
     */
    public function applyRecord(Specimen $specimen, ?array $raw): bool
    {
        if (!$raw) return false;

        $data = $this->normaliseOccurrence($raw);

        if (!$data) {
            // georeference.it no longer stands behind a coordinate it
            // previously gave us — e.g. an automatic suggestion later
            // reviewed and downgraded to "not_georeferenceable". Clear it
            // rather than keep serving a value georeference.it itself has
            // retracted. Only touch it when we're the ones who set it in
            // the first place; a GBIF/Bionomia coordinate is never cleared
            // just because georeference.it has nothing to say about it.
            if ($specimen->coord_source === 'georeference') {
                $specimen->update([
                    'lat' => null, 'lon' => null,
                    'coord_source' => null, 'coord_status' => null,
                    'coord_uncertainty_m' => null,
                ]);
                return true;
            }
            return false;
        }

        // Location text fields aren't something georeference.it curates —
        // only fill a genuine gap, never overwrite what GBIF/Bionomia gave us.
        $data['higher_geography'] = $specimen->higher_geography ?: ($raw['higherGeography'] ?? null);
        $data['state_province']   = $specimen->state_province   ?: ($raw['stateProvince']   ?? null);
        $data['county']           = $specimen->county           ?: ($raw['county']           ?? null);
        $data['municipality']     = $specimen->municipality     ?: ($raw['municipality']     ?? null);

        $specimen->update($data);
        return true;
    }
}
