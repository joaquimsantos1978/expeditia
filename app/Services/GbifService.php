<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Specimen;

class GbifService
{
    const API_URL    = 'https://api.gbif.org/v1/';
    const USER_AGENT = 'Expeditia/2.0 (https://expeditia.info)';

    /**
     * Get a single occurrence by ID
     */
public function getOccurrence(string $id): ?array
{
    try {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->connectTimeout(10)
            ->timeout(280)
            ->get(self::API_URL . 'occurrence/' . $id);
    } catch (\Illuminate\Http\Client\ConnectionException $e) {
        // One unreachable/slow GBIF call must not 500 a whole sync step —
        // callers already treat null as "no data for this occurrence".
        \Log::warning("GBIF occurrence {$id} unreachable: " . $e->getMessage());
        return null;
    }

    return $response->ok()
        ? json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING)
        : null;
}

public function getOccurrencesByExpeditionQid(string $qid): array
{
    $results     = [];
    $wikidataUri = 'http://www.wikidata.org/entity/' . $qid;

    foreach (['parentEventID', 'eventID'] as $term) {
        $offset = 0;
        do {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(280)
                ->get(self::API_URL . 'occurrence/search', [
                    $term    => $wikidataUri,
                    'offset' => $offset,
                    'limit'  => 300,
                ]);

            if (!$response->ok()) break;

            // Use JSON_BIGINT_AS_STRING to avoid 32-bit integer overflow on large GBIF keys
            $decoded = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
            $page    = $decoded['results'] ?? [];
            $results = array_merge($results, $page);
            $offset += 300;

        } while (count($page) > 0);
    }

    return array_values(
        collect($results)
            ->keyBy(fn($r) => (string)($r['key'] ?? uniqid()))
            ->all()
    );
}
    /**
     * Normalise a raw GBIF occurrence into our DB structure
     */
    public function normaliseOccurrence(array $specimen): array
    {
        $image = $specimen['media'][0]['identifier'] ?? null;

        // Clean up event date
        $eventDate = $specimen['eventDate'] ?? null;
        if ($eventDate && str_contains($eventDate, '/')) {
            $eventDate = strtok($eventDate, '/');
        }
        if ($eventDate && strlen($eventDate) <= 6) {
            $eventDate = null;
        }

        return [
            'gbif_id'          => isset($specimen['key'])
                // sprintf('%u') converts 32-bit signed overflow to correct unsigned value
                // e.g. -1696178118 → "2598789178"
                ? sprintf('%u', $specimen['key'])
                : null,
            'dataset_key'      => $specimen['datasetKey']       ?? null,
            'occurrence_id'    => $specimen['occurrenceID']     ?? null,
            'basis_of_record'  => $specimen['basisOfRecord']    ?? null,
            'scientific_name'  => $specimen['scientificName']   ?? null,
            'family'           => $specimen['family']           ?? null,
            'kingdom'          => $specimen['kingdom']          ?? null,
            'type_status'      => $specimen['typeStatus']       ?? null,
            'recorded_by'      => $specimen['recordedBy']       ?? null,
            'identified_by'    => $specimen['identifiedBy']     ?? null,
            'institution_code' => $specimen['institutionCode']  ?? null,
            'collection_code'  => $specimen['collectionCode']   ?? null,
            'catalog_number'   => $specimen['catalogNumber']    ?? null,
            'event_date'       => $eventDate,
            'year'             => $specimen['year']             ?? null,
            'country'          => $specimen['country']          ?? null,
            'country_code'     => $specimen['countryCode']      ?? null,
            'lat'                 => $specimen['decimalLatitude']  ?? null,
            'lon'                 => $specimen['decimalLongitude'] ?? null,
            'coord_uncertainty_m' => $specimen['coordinateUncertaintyInMeters'] ?? null,
            'locality'          => $specimen['locality']         ?? null,
'verbatim_locality' => $specimen['verbatimLocality'] ?? null,
            'higher_geography' => $specimen['higherGeography'] ?? null,
            'state_province'   => $specimen['stateProvince']   ?? null,
            'county'           => $specimen['county']           ?? null,
            'municipality'     => $specimen['municipality']     ?? null,
            // Use the media identifier directly — GBIF's "unsafe" image cache
            // proxy now rejects unsigned requests ("must be signed or use the
            // occurrence API"); the raw identifier is already a working URL.
            'image'            => $image ?: null,
            'gbif_url'         => 'https://www.gbif.org/occurrence/' . ($specimen['key'] ?? ''),
        ];
    }

// How long "complete" data is trusted before we check GBIF again anyway —
// publishers do correct their records over time.
const STALE_DAYS = 90;

/**
 * @param bool $force Bypass the completeness/staleness skip entirely and
 *                     overwrite every field GBIF has a value for — used for
 *                     a user-triggered resync, where "refresh everything"
 *                     is the whole point.
 */
public function enrichSpecimen(Specimen $specimen, bool $force = false): void
{
    if (!$specimen->gbif_id) return;

    // A stale image URL from before we dropped GBIF's now-broken "unsafe"
    // image cache proxy — treat it as missing so it gets refreshed below.
    $hasStaleImage = $specimen->image && str_contains($specimen->image, 'api.gbif.org/v1/image/unsafe/');

    $isStale = !$specimen->gbif_synced_at
        || $specimen->gbif_synced_at->lt(now()->subDays(self::STALE_DAYS));

    // Skip the HTTP call only if there's nothing left for GBIF to improve,
    // we checked recently enough, and this isn't a forced refresh: metadata
    // is complete AND the coordinate already came from GBIF or georeference.it
    // (a Bionomia-sourced coordinate is never "done" here — GBIF is more
    // authoritative than Bionomia's mirrored copy).
    $metadataComplete = $specimen->image && !$hasStaleImage && $specimen->scientific_name && $specimen->institution_code;
    $coordConfirmed   = in_array($specimen->coord_source, ['gbif', 'georeference'], true);
    if (!$force && !$isStale && $metadataComplete && $coordConfirmed) return;

    $raw = $this->getOccurrence($specimen->gbif_id);
    if (!$raw) return;

    $image   = $raw['media'][0]['identifier'] ?? null;
    $gbifLat = $raw['decimalLatitude']  ?? null;
    $gbifLon = $raw['decimalLongitude'] ?? null;

    // Note: deliberately NOT gated on $gbifLat/$gbifLon being non-null — GBIF
    // occasionally retracts a coordinate it previously published (e.g. a bad
    // locality guess later flagged and removed). A forced refresh must be
    // able to clear that stale value, not just replace it with a new one.
    $shouldReplaceCoord = $specimen->coord_source !== 'georeference'
        && ($force || $specimen->coord_source === 'bionomia');

    if ($shouldReplaceCoord) {
        // GBIF is more authoritative than Bionomia's mirrored copy — replace
        // it (also covers a forced refresh, in case GBIF's own value changed
        // or was removed).
        $newLat = $gbifLat;
        $newLon = $gbifLon;
    } else {
        // Otherwise only fill a genuine gap; never overwrite a coordinate
        // georeference.it already set.
        $newLat = $specimen->lat ?: $gbifLat;
        $newLon = $specimen->lon ?: $gbifLon;
    }
    $coordChanged = ($newLat != $specimen->lat) || ($newLon != $specimen->lon);

    // On a forced refresh, GBIF's current value always wins (the publisher
    // may have corrected it); otherwise only fill a genuine gap.
    $pick = fn($current, $fresh) => ($force && $fresh !== null) ? $fresh : ($current ?: $fresh);

    $specimen->update([
        // Raw media identifier — GBIF's "unsafe" image cache proxy now
        // rejects unsigned requests ("must be signed or use the occurrence API").
        // A stale broken URL is always replaced (even by null, since a known
        // dead link is worse than no image).
        'image'            => ($hasStaleImage || $force) ? ($image ?: null) : ($specimen->image ?: ($image ?: null)),
        'locality'         => $pick($specimen->locality, $raw['locality'] ?? null),
        'verbatim_locality'=> $pick($specimen->verbatim_locality, $raw['verbatimLocality'] ?? null),
        'scientific_name'  => $pick($specimen->scientific_name, $raw['scientificName'] ?? null),
        'family'           => $pick($specimen->family, $raw['family'] ?? null),
        'kingdom'          => $pick($specimen->kingdom, $raw['kingdom'] ?? null),
        'institution_code' => $pick($specimen->institution_code, $raw['institutionCode'] ?? null),
        'collection_code'  => $pick($specimen->collection_code, $raw['collectionCode'] ?? null),
        'catalog_number'   => $pick($specimen->catalog_number, $raw['catalogNumber'] ?? null),
        'recorded_by'      => $pick($specimen->recorded_by, $raw['recordedBy'] ?? null),
        'identified_by'    => $pick($specimen->identified_by, $raw['identifiedBy'] ?? null),
        'type_status'      => $pick($specimen->type_status, $raw['typeStatus'] ?? null),
        'country'          => $pick($specimen->country, $raw['country'] ?? null),
        'country_code'     => $pick($specimen->country_code, $raw['countryCode'] ?? null),
        'higher_geography' => $pick($specimen->higher_geography, $raw['higherGeography'] ?? null),
        'state_province'   => $pick($specimen->state_province, $raw['stateProvince'] ?? null),
        'county'           => $pick($specimen->county, $raw['county'] ?? null),
        'municipality'     => $pick($specimen->municipality, $raw['municipality'] ?? null),
        'lat'                 => $newLat,
        'lon'                 => $newLon,
        // A coordinate that got cleared has no source anymore either.
        'coord_source'        => $coordChanged ? ($newLat !== null ? 'gbif' : null) : $specimen->coord_source,
        // Uncertainty describes this specific lat/lon pair — only update it
        // when we actually changed the coordinate, so it never ends up
        // paired with a coordinate from a different source.
        'coord_uncertainty_m' => $coordChanged ? ($raw['coordinateUncertaintyInMeters'] ?? null) : $specimen->coord_uncertainty_m,
        'gbif_synced_at'      => now(),
    ]);
}

    /**
     * Look up a natural history institution by its code in GBIF's GRSciColl registry.
     * Returns ['name' => ..., 'homepage' => ..., 'country' => ...] or null.
     */
    public function getInstitutionInfo(string $code): ?array
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(10)
            ->get(self::API_URL . 'grscicoll/institution', ['code' => $code, 'limit' => 5]);

        if (!$response->ok()) return null;

        $results = $response->json()['results'] ?? [];
        if (empty($results)) return null;

        // Prefer an exact code match
        foreach ($results as $inst) {
            if (($inst['code'] ?? '') === $code) {
                return [
                    'name'     => $inst['name']                         ?? null,
                    'homepage' => $inst['homepage']                     ?? null,
                    'country'  => $inst['address']['country']           ?? null,
                    'city'     => $inst['address']['city']              ?? null,
                    'gbif_key' => $inst['key']                          ?? null,
                ];
            }
        }

        // Fall back to first result
        $inst = $results[0];
        return [
            'name'     => $inst['name']              ?? null,
            'homepage' => $inst['homepage']          ?? null,
            'country'  => $inst['address']['country'] ?? null,
            'city'     => $inst['address']['city']    ?? null,
            'gbif_key' => $inst['key']               ?? null,
        ];
    }

}
