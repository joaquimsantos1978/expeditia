<?php

namespace App\Services;

use App\Models\Expedition;
use App\Models\MapItem;

class MapBuilderService
{
    /**
     * Rebuild the map_items table for a given expedition.
     * Merges points (Wikidata) and specimens (GBIF/Bionomia) into a single ordered list.
     */
    public function build(Expedition $expedition): int
    {
        $items = [];

        // --- 1. Collect Wikidata itinerary points ---
        foreach ($expedition->points as $point) {
            $sortKey = $this->toSortKey(
                $point->point_in_time ?? $point->start_time ?? $expedition->start_time
            );

            $items[] = [
                'sort_key'   => $sortKey,
                'type'       => 'point',
                'source_id'  => $point->id,
                'date_label' => $this->buildDateLabel($point->point_in_time, $point->start_time, $point->end_time),
                'name'       => $point->name,
                'description'=> $point->description,
                'image'      => $point->image,
                'lat'        => $point->lat,
                'lon'        => $point->lon,
            ];
        }

        // --- 2. Collect relevant specimens ---
foreach ($expedition->specimens as $specimen) {
    $sortKey = $this->toSortKey($specimen->event_date);

    $items[] = [
        'sort_key'    => $sortKey,
        'type'        => 'specimen',
        'source_id'   => $specimen->id,
        'date_label'  => $this->stripTime($specimen->event_date),
        'name'        => $specimen->scientific_name,
        'description' => $this->buildSpecimenDescription($specimen),
        'image'       => $specimen->image,
        'lat'         => $specimen->lat ?: null,
        'lon'         => $specimen->lon ?: null,
    ];
}
        // --- 3. Sort by date ---
        usort($items, fn($a, $b) => strcmp($a['sort_key'], $b['sort_key']));

        // --- 4. Wipe old map items and insert new ones ---
        MapItem::where('expedition_id', $expedition->id)->delete();

        foreach ($items as $order => $item) {
            MapItem::create([
                'expedition_id' => $expedition->id,
                'order'         => $order + 1,
                'type'          => $item['type'],
                'source_id'     => $item['source_id'],
                'date_label'    => $item['date_label'],
                'name'          => $item['name'],
                'description'   => $item['description'],
                'image'         => $item['image'],
                'lat'           => $item['lat'],
                'lon'           => $item['lon'],
            ]);
        }

        return count($items);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Convert a partial date string (e.g. "1799", "1799-03", "1799-03-15")
     * to a zero-padded sortable string.
     */
    private function toSortKey(?string $date): string
    {
        if (!$date) return '9999-99-99';

        $parts = explode('-', $date);
        $year  = str_pad($parts[0] ?? '9999', 4, '0', STR_PAD_LEFT);
        $month = str_pad($parts[1] ?? '99',   2, '0', STR_PAD_LEFT);
        $day   = str_pad($parts[2] ?? '99',   2, '0', STR_PAD_LEFT);

        return "$year-$month-$day";
    }

    /**
     * Build a human-readable date label.
     * - point_in_time only  → "1832-01-15"
     * - start only          → "1832-01-15"
     * - start + end         → "1832-01-15 – 1832-03-04"
     * - end only            → "– 1832-03-04"
     */
    private function buildDateLabel(?string $pointInTime, ?string $start, ?string $end): ?string
    {
        if ($pointInTime) return $pointInTime;
        if ($start && $end && $start !== $end) return $start . ' – ' . $end;
        return $start ?? $end ?? null;
    }

    private function stripTime(?string $date): ?string
    {
        if (!$date) return null;
        // handles both "1832-01-01T00:00:00" and "1832-01-01 00:00:00.000000"
        return preg_replace('/[\sT].*$/', '', $date);
    }

    private function buildSpecimenDescription($specimen): string
    {
        $parts = array_filter([
            $specimen->recorded_by,
            $specimen->catalog_number,
            $specimen->institution_code,
            $specimen->collection_code,
            $specimen->gbif_url
                ? '<a href="' . $specimen->gbif_url . '" target="_blank">GBIF</a>'
                : null,
        ]);

        return implode(' · ', $parts);
    }
}