<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class WikidataService
{
    const SPARQL_URL = 'https://query.wikidata.org/sparql';
    const ENTITY_URL = 'https://www.wikidata.org/wiki/Special:EntityData/';
    const USER_AGENT = 'Expeditia/2.0 (https://expeditia.info)';

    public function getAllExpeditionQids(array $classQids = ['Q366301']): array
    {
        $all = [];

        foreach ($classQids as $classQid) {
            $classQid = trim($classQid);
            $query = '
                SELECT DISTINCT ?item WHERE {
                    ?item p:P31 ?statement0.
                    ?statement0 (ps:P31/(wdt:P279*)) wd:' . $classQid . '.
                }
            ';
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->accept('application/sparql-results+json')
                    ->timeout(60)
                    ->get(self::SPARQL_URL, ['query' => $query, 'format' => 'json']);
            } catch (\Exception $e) {
                \Log::warning("SPARQL failed for {$classQid}: " . $e->getMessage());
                continue;
            }

            if (!$response->ok()) {
                \Log::warning("SPARQL HTTP {$response->status()} for {$classQid}");
                continue;
            }

            $bindings = $response->json('results.bindings') ?? [];
            foreach ($bindings as $b) {
                $all[] = str_replace('http://www.wikidata.org/entity/', '', $b['item']['value']);
            }
        }

        return array_values(array_unique($all));
    }

    public function getEntity(string $qid): ?array
    {
        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(280)
            ->get(self::ENTITY_URL . $qid . '.json');

        if (!$response->ok()) return null;
        return $response->json('entities.' . $qid);
    }

    public function extractExpeditionData(array $entity): array
    {
        // ── Collect all QIDs that need label resolution ───────────────────────
        $qidsToResolve = [];

        $singleProps = ['P4791', 'P138', 'P1534'];
        foreach ($singleProps as $prop) {
            $qid = $entity['claims'][$prop][0]['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($qid) $qidsToResolve[] = $qid;
        }

        $multiProps = ['P1876', 'P664', 'P3712', 'P101', 'P17'];
        foreach ($multiProps as $prop) {
            foreach ($entity['claims'][$prop] ?? [] as $claim) {
                $qid = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
                if ($qid) $qidsToResolve[] = $qid;
            }
        }

        // ── Single batch request to resolve all labels ────────────────────────
        $labelMap = $this->getLabels(array_unique($qidsToResolve));

        // ── Build result using the label map ──────────────────────────────────
        return [
            // existing fields
            'label'            => $entity['labels']['mul']['value']          ?? $entity['labels']['en']['value']          ?? null,
            'description'      => $entity['descriptions']['mul']['value']    ?? $entity['descriptions']['en']['value']    ?? null,
            'image'            => $this->extractImage($entity),
            'start_time'       => $this->extractDate($entity, 'P580'),
            'end_time'         => $this->extractDate($entity, 'P582'),

            // new fields
            'short_name'       => $entity['claims']['P1813'][0]['mainsnak']['datavalue']['value']['text'] ?? null,

            'commanded_by'     => $this->pickLabel($entity, 'P4791', $labelMap),
            'vessel'           => $this->pickLabels($entity, 'P1876', $labelMap),
            'organizer'        => $this->pickLabels($entity, 'P664',  $labelMap),
            'goal'             => $this->pickLabels($entity, 'P3712', $labelMap),
            'field_of_work'    => $this->pickLabels($entity, 'P101',  $labelMap),
            'country'          => $this->pickLabels($entity, 'P17',   $labelMap),
            'named_after'      => $this->pickLabel($entity,  'P138',  $labelMap),
            'external_url'     => $entity['claims']['P973'][0]['mainsnak']['datavalue']['value'] ?? null,
            'end_cause'        => $this->pickLabel($entity,  'P1534', $labelMap),
            'follows_qid'      => $entity['claims']['P155'][0]['mainsnak']['datavalue']['value']['id'] ?? null,
            'followed_by_qid'  => $entity['claims']['P156'][0]['mainsnak']['datavalue']['value']['id'] ?? null,
            'num_participants'  => $this->extractQuantity($entity, 'P1132'),
            'num_deaths'        => $this->extractQuantity($entity, 'P1120'),
            'wikipedia_url'     => isset($entity['sitelinks']['enwiki']['title'])
                ? 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $entity['sitelinks']['enwiki']['title'])
                : null,
        ];
    }

    public function extractPersonData(array $entity): array
    {
        $aliases = [];
        foreach ($entity['aliases']['en'] ?? [] as $alias) {
            $aliases[] = $alias['value'];
        }

        $orcid      = $entity['claims']['P496'][0]['mainsnak']['datavalue']['value']    ?? null;
        $bionomiaId = $entity['claims']['P6944'][0]['mainsnak']['datavalue']['value']   ?? $orcid;

        $birthPlaceQid = $entity['claims']['P19'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        $deathPlaceQid = $entity['claims']['P20'][0]['mainsnak']['datavalue']['value']['id'] ?? null;

        $occupationQids = [];
        foreach ($entity['claims']['P106'] ?? [] as $claim) {
            $qid = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($qid) $occupationQids[] = $qid;
        }

        return [
            'name'        => $entity['labels']['mul']['value']       ?? $entity['labels']['en']['value']       ?? null,
            'description' => $entity['descriptions']['mul']['value'] ?? $entity['descriptions']['en']['value'] ?? null,
            'aliases'     => implode(', ', $aliases),
            'image'       => $this->extractImage($entity),
            'date_birth'  => $this->extractDate($entity, 'P569'),
            'date_death'  => $this->extractDate($entity, 'P570'),
            'place_birth' => $birthPlaceQid,
            'place_death' => $deathPlaceQid,
            'occupation'  => implode(', ', $occupationQids),
            'orcid'       => $orcid,
            'bionomia_id' => $bionomiaId,
            'wikipedia_url' => isset($entity['sitelinks']['enwiki']['title'])
                ? 'https://en.wikipedia.org/wiki/' . str_replace(' ', '_', $entity['sitelinks']['enwiki']['title'])
                : null,
            '_birth_place_qid'  => $birthPlaceQid,
            '_death_place_qid'  => $deathPlaceQid,
            '_occupation_qids'  => $occupationQids,
        ];
    }

    const ACTION_API_URL = 'https://www.wikidata.org/w/api.php';

    /**
     * Resolve a single QID to its label. Prefer getLabels() when resolving
     * more than one — this still costs a full batched request either way.
     */
    public function getLabel(string $qid): ?string
    {
        return $this->getLabels([$qid])[$qid] ?? null;
    }

    /**
     * Resolve multiple QIDs to labels in batches of up to 50 via wbgetentities
     * (labels-only projection — far lighter than fetching full entities one
     * QID at a time through Special:EntityData).
     * Returns ['Q123' => 'Label', ...] (missing/unresolved QIDs map to null).
     */
    public function getLabels(array $qids): array
    {
        $qids = array_values(array_unique(array_filter($qids)));
        if (empty($qids)) return [];

        $labels = [];

        foreach (array_chunk($qids, 50) as $chunk) {
            $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->timeout(60)
                ->get(self::ACTION_API_URL, [
                    'action'    => 'wbgetentities',
                    'ids'       => implode('|', $chunk),
                    'props'     => 'labels',
                    'languages' => 'mul|en',
                    'format'    => 'json',
                ]);

            $entities = $response->ok() ? ($response->json('entities') ?? []) : [];

            foreach ($chunk as $qid) {
                $entityLabels = $entities[$qid]['labels'] ?? [];
                $labels[$qid] = $entityLabels['mul']['value'] ?? $entityLabels['en']['value'] ?? null;
            }
        }

        return $labels;
    }

    public function extractParticipantQids(array $entity): array
    {
        $claims = $entity['claims']['P710'] ?? [];
        return array_values(array_filter(array_map(function ($claim) {
            return $claim['mainsnak']['datavalue']['value']['id'] ?? null;
        }, $claims)));
    }

    public function extractItinerary(array $entity): array
    {
        $points = [];

        $startQid = $entity['claims']['P1427'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($startQid) {
            $points[] = [
                'qid'           => $startQid,
                'type'          => 'start',
                'point_in_time' => $this->extractDate($entity, 'P580'),
                'start_time'    => null,
                'end_time'      => null,
            ];
        }

        foreach ($entity['claims']['P2825'] ?? [] as $claim) {
            $qid = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
            if (!$qid) continue;
            $qualifiers = $claim['qualifiers'] ?? [];
            $points[] = [
                'qid'           => $qid,
                'type'          => 'via',
                'point_in_time' => $this->extractDateFromQualifier($qualifiers, 'P585'),
                'start_time'    => $this->extractDateFromQualifier($qualifiers, 'P580'),
                'end_time'      => $this->extractDateFromQualifier($qualifiers, 'P582'),
            ];
        }

        $endQid = $entity['claims']['P1444'][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if ($endQid) {
            $points[] = [
                'qid'           => $endQid,
                'type'          => 'end',
                'point_in_time' => $this->extractDate($entity, 'P582'),
                'start_time'    => null,
                'end_time'      => null,
            ];
        }

        // Sort chronologically so processing order is stable across restarts
        usort($points, function ($a, $b) {
            $dateA = $a['point_in_time'] ?? $a['start_time'] ?? $a['end_time'] ?? '';
            $dateB = $b['point_in_time'] ?? $b['start_time'] ?? $b['end_time'] ?? '';
            return strcmp($dateA, $dateB);
        });

        return $points;
    }

    public function extractGbifOccurrenceIds(array $entity): array
    {
        $ids = [];
        $gbifDomain = 'https://www.gbif.org/occurrence/';
        foreach ($entity['claims']['P11146'] ?? [] as $claim) {
            $url = $claim['references'][0]['snaks']['P854'][0]['datavalue']['value'] ?? null;
            if ($url && str_starts_with($url, $gbifDomain)) {
                $ids[] = str_replace($gbifDomain, '', $url);
            }
        }
        return array_unique($ids);
    }

    public function getPlaceDetails(string $qid): ?array
    {
        $entity = $this->getEntity($qid);
        if (!$entity) return null;

        return $this->placeFromEntity($entity);
    }

    /**
     * Fetch several entities at once via wbgetentities, $chunk QIDs per request
     * (the API allows 50; claims-heavy people are smaller to keep responses
     * reasonable). Same entity shape as Special:EntityData's entities[QID], so
     * the extract*() helpers work unchanged. Deleted/unknown items are left out
     * of the result. $extra adds request params (e.g. sitefilter).
     *
     * Returns null when Wikidata couldn't be reached or answered badly — callers
     * must not treat that as "these entities don't exist".
     */
    public function getEntities(array $qids, string $props, int $chunk = 50, array $extra = []): ?array
    {
        $qids   = array_values(array_unique(array_filter($qids)));
        $result = [];

        foreach (array_chunk($qids, $chunk) as $ids) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(60)
                    ->retry(2, 500, throw: false)
                    ->get(self::ACTION_API_URL, $extra + [
                        'action'    => 'wbgetentities',
                        'ids'       => implode('|', $ids),
                        'props'     => $props,
                        'languages' => 'mul|en',
                        'format'    => 'json',
                    ]);
            } catch (\Throwable $e) {
                \Log::warning('Wikidata entity batch failed: ' . $e->getMessage());
                return null;
            }

            if (!$response->ok() || !is_array($response->json('entities'))) {
                \Log::warning('Wikidata entity batch failed: HTTP ' . $response->status());
                return null;
            }

            foreach ($ids as $qid) {
                $entity = $response->json('entities')[$qid] ?? null;
                if ($entity && !isset($entity['missing'])) $result[$qid] = $entity;
            }
        }

        return $result;
    }

    /**
     * Place details for many QIDs at once: one wbgetentities request per 50
     * distinct QIDs instead of one full Special:EntityData download per point.
     * Duplicates collapse (a voyage that starts and ends at the same port is
     * one lookup).
     *
     * Deliberately NOT cached across syncs: the point of a sync is that edits
     * made on Wikidata show up in Expeditia immediately.
     *
     * Returns ['Q123' => [name, description, image, lat, lon]|null, ...], or
     * null when Wikidata couldn't be reached — callers must not treat that as
     * "these places don't exist".
     */
    public function getPlaceDetailsMany(array $qids): ?array
    {
        $qids = array_values(array_unique(array_filter($qids)));
        if (!$qids) return [];

        $entities = $this->getEntities($qids, 'labels|descriptions|claims');
        if ($entities === null) return null;

        $result = [];
        foreach ($qids as $qid) {
            // Deleted/unknown QIDs are absent from getEntities()'s result.
            $result[$qid] = isset($entities[$qid]) ? $this->placeFromEntity($entities[$qid]) : null;
        }

        return $result;
    }

    private function placeFromEntity(array $entity): array
    {
        return [
            'name'        => $entity['labels']['mul']['value']       ?? $entity['labels']['en']['value']       ?? null,
            'description' => $entity['descriptions']['mul']['value'] ?? $entity['descriptions']['en']['value'] ?? null,
            'image'       => $this->extractImage($entity),
            'lat'         => $entity['claims']['P625'][0]['mainsnak']['datavalue']['value']['latitude']  ?? null,
            'lon'         => $entity['claims']['P625'][0]['mainsnak']['datavalue']['value']['longitude'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Pick a single label from the pre-fetched label map for a single-value property.
     */
    private function pickLabel(array $entity, string $property, array $labelMap): ?string
    {
        $qid = $entity['claims'][$property][0]['mainsnak']['datavalue']['value']['id'] ?? null;
        if (!$qid) return null;
        $label = $labelMap[$qid] ?? $qid;
        return $label . '||' . $qid;
    }

    /**
     * Pick labels from the pre-fetched label map for a multi-value property.
     * Stored as "Label1||Q1, Label2||Q2" — parseable in views.
     */
    private function pickLabels(array $entity, string $property, array $labelMap): ?string
    {
        $pairs = [];
        foreach ($entity['claims'][$property] ?? [] as $claim) {
            $qid = $claim['mainsnak']['datavalue']['value']['id'] ?? null;
            if ($qid) {
                $label   = $labelMap[$qid] ?? $qid;
                $pairs[] = $label . '||' . $qid;
            }
        }
        return empty($pairs) ? null : implode(',,', $pairs);
    }

    /**
     * Extract a quantity value (e.g. number of participants).
     */
    private function extractQuantity(array $entity, string $property): ?int
    {
        $amount = $entity['claims'][$property][0]['mainsnak']['datavalue']['value']['amount'] ?? null;
        return $amount !== null ? (int) abs((float) $amount) : null;
    }

    private function extractImage(array $entity): ?string
    {
        $image = $entity['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
        if (!$image) return null;
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . str_replace(' ', '_', $image) . '?width=800';
    }

    private function extractDate(array $entity, string $property): ?string
    {
        $time = $entity['claims'][$property][0]['mainsnak']['datavalue']['value']['time'] ?? null;
        return $time ? $this->formatWikidataDate($time) : null;
    }

    private function extractDateFromQualifier(array $qualifiers, string $property): ?string
    {
        $time = $qualifiers[$property][0]['datavalue']['value']['time'] ?? null;
        return $time ? $this->formatWikidataDate($time) : null;
    }

    private function formatWikidataDate(string $time): string
    {
        $time  = ltrim($time, '+');
        $parts = explode('T', $time)[0];
        [$year, $month, $day] = explode('-', $parts);
        $year  = preg_replace('/\D/', '', $year);
        $date  = $year > 0 ? $year : '';
        if ($month > 0) $date .= '-' . $month;
        if ($day   > 0) $date .= '-' . $day;
        return $date;
    }
}