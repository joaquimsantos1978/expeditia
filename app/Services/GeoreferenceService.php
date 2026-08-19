<?php

namespace App\Services;

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
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(15)
            ->get(self::API_URL . 'occurrences/' . $gbifId);

        return $response->ok() ? $response->json() : null;
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

        $raw = $this->lookupByGbifId($specimen->gbif_id);
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
