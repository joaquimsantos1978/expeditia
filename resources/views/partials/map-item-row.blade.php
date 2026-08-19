@php $expIndex = $expIndex ?? null; @endphp
<div class="list-group-item d-flex align-items-start gap-2 gap-md-3 py-2 map-item-row {{ !$item->lat ? 'no-coords' : '' }}"
     data-id="{{ $item->id }}"
     data-type="{{ $item->type }}"
     data-lat="{{ $item->lat }}"
     data-lon="{{ $item->lon }}"
     @if($expIndex !== null) data-exp-index="{{ $expIndex }}" @endif
     style="cursor:{{ $item->lat ? 'pointer' : 'default' }}">

    <div class="mt-1 flex-shrink-0">
        @if($item->lat)
            @if($expIndex !== null)
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:var(--exp-color-{{ $expIndex }});"></span>
            @else
                <span class="timeline-dot {{ $item->type === 'specimen' ? 'specimen' : '' }}"></span>
            @endif
        @else
            <span style="font-size:14px;opacity:.4;">{{ $item->type === 'specimen' ? '🔬' : '📍' }}</span>
        @endif
    </div>

    <div class="flex-grow-1 min-width-0" style="min-width:0;">
        <div class="d-flex justify-content-between gap-2">
            <strong class="small text-truncate">{{ $item->name ?? '—' }}</strong>
            <span class="text-muted small text-nowrap">{{ $item->date_label }}</span>
        </div>

        @if($item->type === 'specimen')
            @php
                $locationParts = collect([
                    $item->verbatim_locality ?? null,
                    $item->locality ?? null,
                ])->filter()->unique()->values();
            @endphp
            @if($locationParts->count())
                <p class="small text-muted mb-0">📍 {{ $locationParts->implode(' · ') }}</p>
            @endif
        @endif

        @if($item->description)
            <p class="small text-muted mb-0">{{ Str::limit(strip_tags($item->description), 120) }}</p>
        @endif

        @if($item->type === 'specimen')
        <div class="d-flex flex-wrap align-items-center gap-2 mt-1">
            @if(!$item->lat)
                <span class="badge bg-light text-muted" style="font-size:10px;">no coordinates</span>
            @endif
            @if(isset($item->gbif_id) && $item->gbif_id)
                <a href="https://www.gbif.org/occurrence/{{ $item->gbif_id }}"
                   target="_blank"
                   class="small text-muted text-decoration-none"
                   onclick="event.stopPropagation()">GBIF ↗</a>
            @endif
            <button class="btn btn-link btn-sm p-0 small text-muted"
                    onclick="event.stopPropagation(); showSpecimenModal(this)"
                    data-image="{{ $item->specimen_image ?? '' }}"
                    data-name="{{ $item->name ?? '' }}"
                    data-scientific="{{ $item->scientific_name ?? '' }}"
                    data-family="{{ $item->family ?? '' }}"
                    data-kingdom="{{ $item->kingdom ?? '' }}"
                    data-locality="{{ $item->locality ?? '' }}"
                    data-verbatim="{{ $item->verbatim_locality ?? '' }}"
                    data-date="{{ $item->event_date ?? $item->date_label ?? '' }}"
                    data-year="{{ $item->year ?? '' }}"
                    data-country="{{ $item->country ?? '' }}"
                    data-country-code="{{ $item->country_code ?? '' }}"
                    data-recorded="{{ $item->recorded_by ?? '' }}"
                    data-identified="{{ $item->identified_by ?? '' }}"
                    data-date-identified="{{ $item->date_identified ?? '' }}"
                    data-type-status="{{ $item->type_status ?? '' }}"
                    data-institution="{{ $item->institution_code ?? '' }}"
                    data-collection="{{ $item->collection_code ?? '' }}"
                    data-catalog="{{ $item->catalog_number ?? '' }}"
                    data-basis="{{ $item->basis_of_record ?? '' }}"
                    data-license="{{ $item->license ?? '' }}"
                    data-occurrence="{{ $item->occurrence_id ?? '' }}"
                    data-gbif="{{ $item->gbif_id ?? '' }}"
                    data-lat="{{ $item->lat ?? '' }}"
                    data-lon="{{ $item->lon ?? '' }}">
                🔍 Details
            </button>
        </div>
        @endif
    </div>

    @php $thumbnail = $item->type === 'specimen' ? ($item->specimen_image ?? null) : ($item->image ?? null); @endphp
    @if($thumbnail)
    <img src="{{ $thumbnail }}"
         loading="lazy"
         style="width:52px;height:52px;object-fit:cover;border-radius:6px;flex-shrink:0;{{ $item->type === 'specimen' ? 'cursor:pointer;' : '' }}"
         @if($item->type === 'specimen')
         onclick="event.stopPropagation(); this.closest('.map-item-row').querySelector('[onclick*=showSpecimenModal]')?.click()"
         title="Click to view details"
         @endif
         onerror="this.style.display='none'">
    @endif
</div>
