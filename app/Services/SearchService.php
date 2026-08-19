<?php

namespace App\Services;

use App\Models\Expedition;
use App\Models\Person;

class SearchService
{
    // -------------------------------------------------------------------------
    // Build and store search_text for an expedition.
    // Called after mapBuilder->build() — at that point everything is available:
    // meta, itinerary points, GBIF specimens, Bionomia specimens.
    // -------------------------------------------------------------------------
    public function indexExpedition(Expedition $expedition): void
    {
        $expedition->loadMissing(['people', 'points']);

        $parts = array_filter([
            $expedition->label,
            $expedition->short_name,
            $expedition->description,
            $expedition->commanded_by  ? explode('||', $expedition->commanded_by)[0]  : null,
            $expedition->vessel        ? explode('||', $expedition->vessel)[0]        : null,
            $expedition->organizer     ? explode('||', $expedition->organizer)[0]     : null,
            $expedition->goal,
            $expedition->field_of_work ? explode('||', $expedition->field_of_work)[0] : null,
            $expedition->country       ? explode('||', $expedition->country)[0]       : null,
            $expedition->named_after   ? explode('||', $expedition->named_after)[0]   : null,
            $expedition->end_cause,
            $expedition->wikidata_qid,

            $expedition->people->map(fn($p) => implode(' ', array_filter([
                $p->name, $p->aliases, $p->occupation,
            ])))->implode(' '),

            $expedition->points->pluck('name')->implode(' '),
        ]);

        // Load specimen text fields in chunks to avoid memory exhaustion
        $specimenParts = [];
        $expedition->specimens()
            ->select(['scientific_name','family','kingdom','recorded_by',
                      'identified_by','locality','verbatim_locality','country',
                      'institution_code','collection_code'])
            ->chunk(200, function ($chunk) use (&$specimenParts) {
                foreach ($chunk as $s) {
                    $specimenParts[] = implode(' ', array_filter([
                        $s->scientific_name, $s->family, $s->kingdom,
                        $s->recorded_by, $s->identified_by,
                        $s->locality, $s->verbatim_locality,
                        $s->country, $s->institution_code, $s->collection_code,
                    ]));
                }
            });

        $parts[] = implode(' ', $specimenParts);

        $expedition->search_text = $this->normalize(implode(' ', array_filter($parts)));
        $expedition->saveQuietly();
    }

    // -------------------------------------------------------------------------
    // Build and store search_text for a person.
    // Called after linkSpecimensToExpedition — specimens are available at that point.
    // -------------------------------------------------------------------------
    public function indexPerson(Person $person): void
    {
        $person->loadMissing(['expeditions']);

        $parts = array_filter([
            $person->name,
            $person->aliases,
            $person->description,
            $person->occupation,
            $person->place_birth,
            $person->place_death,
            $person->wikidata_qid,
            $person->orcid,

            $person->expeditions->map(fn($e) => implode(' ', array_filter([
                $e->label, $e->short_name,
                $e->country       ? explode('||', $e->country)[0]  : null,
                $e->vessel        ? explode('||', $e->vessel)[0]   : null,
            ])))->implode(' '),
        ]);

        // Specimen text in chunks
        $specimenParts = [];
        $person->specimens()
            ->select(['scientific_name','family','kingdom','locality','verbatim_locality','country'])
            ->chunk(200, function ($chunk) use (&$specimenParts) {
                foreach ($chunk as $s) {
                    $specimenParts[] = implode(' ', array_filter([
                        $s->scientific_name, $s->family, $s->kingdom,
                        $s->locality, $s->verbatim_locality, $s->country,
                    ]));
                }
            });

        $parts[] = implode(' ', $specimenParts);

        $person->search_text = $this->normalize(implode(' ', array_filter($parts)));
        $person->saveQuietly();
    }

    // -------------------------------------------------------------------------
    // Normalize: transliterate to ASCII, lowercase, collapse whitespace.
    // "Júlio Augusto Henriques" → "julio augusto henriques"
    // -------------------------------------------------------------------------
    private function normalize(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $lower = strtolower($ascii);
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower);
        return preg_replace('/\s+/', ' ', trim($clean));
    }
}
