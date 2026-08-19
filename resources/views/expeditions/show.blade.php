@extends('layouts.app')

@section('title', $expedition->label)

@section('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    @include('partials.map-styles')
    @media (max-width: 768px) {
        .expedition-header-image { margin-top: 1rem; }
        .leaflet-popup-content { font-size: 13px; }
        .leaflet-popup-content img { max-height: 120px; }
    }
</style>
@endsection

@section('content')

{{-- Header --}}
<div class="row mb-4 align-items-start">
    <div class="col-lg-8">
        <h1 class="h2">
            {{ $expedition->label }}
            @if($expedition->short_name && $expedition->short_name !== $expedition->label)
                <small class="text-muted fs-5">({{ $expedition->short_name }})</small>
            @endif
        </h1>

        {{-- Action buttons --}}
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <a href="{{ route('expeditions.export.csv',  $expedition->wikidata_qid) }}" class="btn btn-sm btn-outline-secondary">⬇ CSV</a>
            <a href="{{ route('expeditions.export.json', $expedition->wikidata_qid) }}" class="btn btn-sm btn-outline-secondary">⬇ JSON</a>
            <a href="{{ url('/api/expedition/' . $expedition->wikidata_qid) }}" target="_blank" class="btn btn-sm btn-outline-secondary">API ↗</a>
            <button class="btn btn-sm btn-outline-secondary"
                    onclick="startSync('{{ route('sync.resync', $expedition->wikidata_qid) }}', this)">
                <i class="bi bi-arrow-clockwise"></i> Sync
            </button>
        </div>
        @include('partials.sync-panel')

        @if($expedition->description)
        <p class="lead text-muted">{{ $expedition->description }}</p>
        @endif

        {{-- Dates + Wikidata + Wikipedia --}}
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3 text-muted small">
            @if($expedition->start_time)
            <span>
                📅 <strong class="text-dark">{{ $expedition->start_time }}</strong>
                @if($expedition->end_time) – <strong class="text-dark">{{ $expedition->end_time }}</strong> @endif
            </span>
            @endif
            <a href="https://www.wikidata.org/wiki/{{ $expedition->wikidata_qid }}"
               target="_blank" class="text-muted">
                <img src="https://www.wikidata.org/static/favicon/wikidata.ico" width="14" height="14" style="vertical-align:text-bottom;margin-right:3px;" alt="">{{ $expedition->wikidata_qid }} ↗
            </a>
            @if($expedition->wikipedia_url)
            <button class="wiki-btn btn btn-sm btn-outline-secondary"
                    data-qid="{{ $expedition->wikidata_qid }}"
                    data-label="{{ $expedition->label }}"
                    data-wp-url="{{ $expedition->wikipedia_url }}">
                <i class="bi bi-wikipedia"></i> Wikipedia
            </button>
            @endif
        </div>

        {{-- Metadata badges --}}
        @php
        $metaRows = array_filter([
            $expedition->commanded_by    ? ['bi-person-badge',  'Commanded by',  $expedition->commanded_by]    : null,
            $expedition->vessel          ? ['bi-tsunami',        'Vessel',        $expedition->vessel]          : null,
            $expedition->organizer       ? ['bi-building',       'Organizer',     $expedition->organizer]       : null,
            $expedition->field_of_work   ? ['bi-eyedropper',    'Field of work', $expedition->field_of_work]   : null,
            $expedition->country         ? ['bi-globe',          'Country',       $expedition->country]         : null,
            $expedition->goal            ? ['bi-crosshair',      'Goal',          $expedition->goal]            : null,
            $expedition->named_after     ? ['bi-tag',            'Named after',   $expedition->named_after]     : null,
            $expedition->end_cause       ? ['bi-flag',           'End cause',     $expedition->end_cause]       : null,
            $expedition->num_participants ? ['bi-people',        'Participants', (string)$expedition->num_participants] : null,
            $expedition->num_deaths      ? ['bi-heartbreak',    'Deaths',        (string)$expedition->num_deaths]      : null,
        ]);
        @endphp

        @if(!empty($metaRows))
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach($metaRows as [$icon, $lbl, $val])
            <span class="badge bg-light text-dark border px-3 py-2 d-inline-flex align-items-center gap-1" style="font-size:.8rem;border-radius:6px;">
                <i class="bi {{ $icon }} text-muted"></i>
                <strong class="fw-semibold">{{ $lbl }}:</strong>
                @php
                    $items = strpos((string)$val, '||') !== false
                        ? array_map(fn($p) => explode('||', $p, 2), explode(',,', $val))
                        : [[(string)$val, null]];
                @endphp
                @foreach($items as $i => [$itemLabel, $itemQid])
                    @if($i > 0)<span class="text-muted">,</span>@endif
                    @if(!empty($itemQid))
                        <span class="wd-entity" data-qid="{{ $itemQid }}" data-label="{{ $itemLabel }}"
                              style="cursor:pointer;text-decoration:underline dotted;">{{ $itemLabel }}</span>
                    @else
                        {{ $itemLabel }}
                    @endif
                @endforeach
            </span>
            @endforeach
        </div>
        @endif

        {{-- External link --}}
        @if($expedition->external_url)
        <div class="mb-2">
            <a href="{{ $expedition->external_url }}" target="_blank" class="small text-muted">
                🔗 External resource ↗
            </a>
        </div>
        @endif

        {{-- Follows / followed by --}}
        @if($expedition->follows_qid || $expedition->followed_by_qid)
        <div class="mb-2 small text-muted d-flex flex-wrap gap-3">
            @if($expedition->follows_qid)
            <span>← Follows:
                <a href="https://www.wikidata.org/wiki/{{ $expedition->follows_qid }}" target="_blank" class="text-muted">
                    {{ $expedition->follows_qid }} ↗
                </a>
            </span>
            @endif
            @if($expedition->followed_by_qid)
            <span>→ Followed by:
                <a href="https://www.wikidata.org/wiki/{{ $expedition->followed_by_qid }}" target="_blank" class="text-muted">
                    {{ $expedition->followed_by_qid }} ↗
                </a>
            </span>
            @endif
        </div>
        @endif

        {{-- Participants --}}
        @if($expedition->people->count())
        <div class="mb-3">
            <strong>Participants:</strong>
            @foreach($expedition->people as $person)
            <a href="{{ route('people.show', $person->wikidata_qid) }}"
               class="badge bg-secondary text-decoration-none me-1">
                {{ $person->name ?? $person->wikidata_qid }}
            </a>
            @endforeach
        </div>
        @endif
    </div>

    @if($expedition->image)
    <div class="col-lg-4 expedition-header-image">
        <img src="{{ $expedition->image }}" class="img-fluid rounded" alt="{{ $expedition->label }}">
    </div>
    @endif
</div>

{{-- Map --}}
@include('partials.map')

{{-- List --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <strong>Itinerary & Specimens</strong>
            <span class="text-muted small ms-2" id="itemsCount">{{ $totalMapItems }} items</span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group btn-group-sm" role="group" id="filterButtons">
                <button type="button" class="btn btn-outline-secondary active" data-filter="all">All</button>
                <button type="button" class="btn btn-outline-primary" data-filter="point">📍 Points</button>
                <button type="button" class="btn btn-outline-success" data-filter="specimen">🔬 Specimens</button>
            </div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="btnPrev">←</button>
                <span class="btn btn-outline-secondary disabled" id="navLabel" style="min-width:60px;">— / —</span>
                <button class="btn btn-outline-secondary" id="btnNext">→</button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        {{-- overflow-x:auto prevents table from breaking page width --}}
        <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto;overflow-x:hidden;" id="itemsList">
            <div class="text-center text-muted py-4 small" id="itemsLoading">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@include('partials.map-scripts')
<script>
    const map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    const QID    = '{{ $expedition->wikidata_qid }}';
    const markers = {};
    const allPoints = [];
    const datedPoints = []; // only items with a date_label — used for the route line
    let   cluster   = null;  // exposed for applyFilter
    const itemCache = {};
    let   allItems  = [];

    function escHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Draws a route as one or more polylines, splitting at the antimeridian
    // (±180°) so a jump like 178° → -175° takes the short way across the
    // Pacific instead of a straight (wrong) line all the way across the map.
    function drawGeoPolyline(map, points, style) {
        let segment = [];
        points.forEach((pt, i) => {
            if (i > 0 && Math.abs(pt[1] - points[i - 1][1]) > 180) {
                if (segment.length > 1) L.polyline(segment, style).addTo(map);
                segment = [];
            }
            segment.push(pt);
        });
        if (segment.length > 1) L.polyline(segment, style).addTo(map);
    }

    // ── Render placeholder rows from lightweight data ─────────────────────
    function activateDetailsBtn(row) {
        const btn = row.querySelector('.details-btn');
        if (!btn) return;
        btn.disabled = false;
        btn.innerHTML = '🔍 Details';
    }

    function renderPlaceholder(item) {
        const hasDot = item.lat
            ? `<span class="timeline-dot ${item.type==='specimen'?'specimen':''}"></span>`
            : `<span style="font-size:14px;opacity:.4;">${item.type==='specimen'?'🔬':'📍'}</span>`;
        const _thumb = item.type === 'point' ? item.image : item.specimen_image;
        const img = _thumb
            ? (item.type === 'point'
                ? `<img src="${escHtml(_thumb)}" style="width:52px;height:52px;object-fit:cover;border-radius:6px;flex-shrink:0;" onerror="this.style.display='none'" loading="lazy">`
                : `<img src="${escHtml(_thumb)}" style="width:52px;height:52px;object-fit:cover;border-radius:6px;flex-shrink:0;cursor:pointer;" onclick="event.stopPropagation();loadAndShowModal(${item.id})" onerror="this.style.display='none'" loading="lazy">`)
            : '';
        const loc = [item.verbatim_locality, item.locality].filter(Boolean).filter((v,i,a)=>a.indexOf(v)===i);
        const locHtml = loc.length ? `<p class="small text-muted mb-0">📍 ${escHtml(loc.join(' · '))}</p>` : '';
        const collectorHtml = item.recorded_by ? `<p class="small text-muted mb-0">👤 ${escHtml(item.recorded_by)}</p>` : '';
        const noCoords = !item.lat ? `<span class="badge bg-light text-muted" style="font-size:10px;">no coordinates</span>` : '';
        const gbifLink = item.gbif_id ? `<a href="https://www.gbif.org/occurrence/${item.gbif_id}" target="_blank" class="small text-muted text-decoration-none" onclick="event.stopPropagation()">GBIF ↗</a>` : '';
        const institutionBadge = (item.institution_code)
            ? `<span class="institution-badge">${escHtml(item.institution_code)}${item.collection_code ? ' / ' + escHtml(item.collection_code) : ''}</span>`
            : '';
        const detailsBtn = item.type==='specimen'
            ? `<div class="d-flex flex-wrap align-items-center gap-2 mt-1">${institutionBadge}${noCoords}${gbifLink}<button class="btn btn-link btn-sm p-0 small text-muted details-btn" data-id="${item.id}" onclick="event.stopPropagation();loadAndShowModal(${item.id})" disabled><span class="spinner-border spinner-border-sm" role="status"></span></button></div>`
            : '';
        const desc = (!item.description || item.type==='specimen') ? '' : `<p class="small text-muted mb-0">${escHtml(item.description.substring(0,120))}</p>`;
        return `<div class="list-group-item d-flex align-items-start gap-2 py-2 map-item-row${!item.lat?' no-coords':''}" id="item-${item.id}" data-id="${item.id}" data-type="${escHtml(item.type)}" data-lat="${item.lat||''}" data-lon="${item.lon||''}" style="cursor:${item.lat?'pointer':'default'}">
            <div class="mt-1 flex-shrink-0">${hasDot}</div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="d-flex justify-content-between gap-2">
                    <strong class="small text-truncate">${escHtml(item.name||'—')}</strong>
                    <span class="text-muted small text-nowrap">${escHtml(item.date_label||'')}</span>
                </div>
                ${locHtml}${collectorHtml}${desc}${detailsBtn}
            </div>
            ${img}
        </div>`;
    }

    async function loadAndShowModal(id) {
        const modal  = document.getElementById('specimenModal');
        const isOpen = modal.classList.contains('show');

        if (!itemCache[id]) {
            // If modal is already open (navigation), show immediate loading state
            if (isOpen) {
                document.getElementById('specimenModalTitle').textContent  = '…';
                document.getElementById('specimenModalFamily').textContent = '';
                document.getElementById('specimenModalImageCol').classList.add('d-none');
                document.getElementById('specimenModalGbif').style.display = 'none';
                document.getElementById('specimenMiniMapWrap').style.display = 'none';
                document.getElementById('specimenNoMapWrap').style.display = 'none';
                document.getElementById('specimenModalFields').innerHTML =
                    '<tr><td colspan="2" class="text-center py-5"><div class="spinner-border text-muted" role="status"></div></td></tr>';
            }
            try {
                const r = await fetch(`/expedition/${QID}/item/${id}`);
                itemCache[id] = await r.json();
            } catch(e) { console.error('Item fetch failed', e); return; }
        }

        const d = itemCache[id];
        const dataset = {
            itemId:        id,
            image:         d.specimen_image || '',
            name:          d.name || '',
            scientific:    d.scientific_name || '',
            family:        d.family || '',
            kingdom:       d.kingdom || '',
            locality:      d.locality || '',
            verbatim:      d.verbatim_locality || '',
            higherGeography: d.higher_geography || '',
            stateProvince: d.state_province || '',
            county:        d.county || '',
            municipality:  d.municipality || '',
            date:          d.event_date || '',
            year:          String(d.year || ''),
            country:       d.country || '',
            countryCode:   d.country_code || '',
            recorded:      d.recorded_by || '',
            identified:    d.identified_by || '',
            dateIdentified:d.date_identified || '',
            typeStatus:    d.type_status || '',
            institution:   d.institution_code || '',
            collection:    d.collection_code || '',
            catalog:       d.catalog_number || '',
            basis:         d.basis_of_record || '',
            license:       d.license || '',
            occurrence:    d.occurrence_id || '',
            gbif:          String(d.gbif_id || ''),
            lat:           String(d.lat || ''),
            lon:           String(d.lon || ''),
            coord_uncertainty_m: d.coord_uncertainty_m || null,
        };

        const rows = Array.from(document.querySelectorAll('#itemsList .map-item-row:not(.d-none)[data-type="specimen"]'));
        modalSpecimenRows = rows;
        modalCurrentIndex = rows.findIndex(r => parseInt(r.dataset.id) === id);

        if (isOpen) {
            fillModal(dataset);
            updateModalNavLabel();
        } else {
            fillModal(dataset);
            updateModalNavLabel();
            new bootstrap.Modal(modal).show();
        }
    }
    // ── Load all items via AJAX ───────────────────────────────────────────
    // Init map size regardless of fetch result
    setTimeout(() => { map.invalidateSize(); map.setView([20, 0], 2); }, 200);

    function scrollToRow(id) {
        document.querySelectorAll('.map-item-row').forEach(r => r.classList.remove('active'));
        function tryScroll() {
            const row = document.getElementById(`item-${id}`);
            if (row) {
                row.classList.add('active');
                row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                // Sync currentIndex so next/prev navigation continues from this point
                currentIndex = getVisibleRows().indexOf(row);
                updateNavLabel();
                return;
            }
            if (typeof window.renderNextBatch === 'function' && !window._renderDone) {
                window.renderNextBatch();
                setTimeout(tryScroll, 0);
            }
        }
        tryScroll();
    }

    fetch(`/expedition/${QID}/map-data`)
        .then(r => r.json())
        .then(items => {
            allItems = items; window.allItems = items; window._renderDone = false;
            document.getElementById('itemsLoading')?.remove();

            // ── Windowed list rendering ───────────────────────────────────
            const PAGE = 2000;
            let rendered = 0;
            let prefetchObserver;

            function observeNewRows() {
                prefetchObserver = prefetchObserver || new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (!entry.isIntersecting) return;
                        const row = entry.target;
                        const id  = parseInt(row.dataset.id);
                        if (row.dataset.type !== 'specimen' || itemCache[id]) {
                            activateDetailsBtn(row); prefetchObserver.unobserve(row); return;
                        }
                        fetch(`/expedition/${QID}/item/${id}`)
                            .then(r => r.json()).then(d => { itemCache[id] = d; activateDetailsBtn(row); })
                            .catch(() => activateDetailsBtn(row));
                        prefetchObserver.unobserve(row);
                    });
                }, { rootMargin: '300px' });

                document.querySelectorAll('#itemsList .map-item-row[data-type="specimen"]:not([data-observed])').forEach(row => {
                    row.dataset.observed = '1';
                    prefetchObserver.observe(row);
                });
            }

            window.renderNextBatch = renderNextBatch;
            function renderNextBatch() {
                const batch = items.slice(rendered, rendered + PAGE);
                if (!batch.length) return;
                const frag = document.createElement('div');
                frag.innerHTML = batch.map(renderPlaceholder).join('');
                while (frag.firstChild) document.getElementById('itemsList').appendChild(frag.firstChild);
                rendered += batch.length;
                observeNewRows();

                // Show/hide Load more button
                let loadMoreBtn = document.getElementById('loadMoreBtn');
                if (rendered < items.length) {
                    if (!loadMoreBtn) {
                        loadMoreBtn = document.createElement('button');
                        loadMoreBtn.id = 'loadMoreBtn';
                        loadMoreBtn.className = 'btn btn-outline-secondary btn-sm w-100 my-2';
                        loadMoreBtn.onclick = renderNextBatch;
                        document.getElementById('itemsList').after(loadMoreBtn);
                    }
                    loadMoreBtn.textContent = `Load more (${items.length - rendered} remaining)`;
                } else if (loadMoreBtn) {
                    loadMoreBtn.remove();
                    window._renderDone = true;
                }
            }

            renderNextBatch();
            applyFilter(currentFilter);

            // ── Map markers — canvas renderer for performance ─────────────
            const renderer = L.canvas({ padding: 0.5 });

            items.forEach(item => {
                if (!item.lat || !item.lon) return;
                const isPoint = item.type === 'point';
                const color   = isPoint ? '#0d6efd' : '#198754';

                // Coordinate uncertainty radius (metres) — how much to trust the pin.
                if (item.coord_uncertainty_m) {
                    L.circle([item.lat, item.lon], {
                        renderer, radius: item.coord_uncertainty_m,
                        color, weight: 1, fillColor: color, fillOpacity: 0.08,
                        interactive: false,
                    }).addTo(map);
                }

                const marker  = L.circleMarker([item.lat, item.lon], {
                    renderer, radius: isPoint ? 10 : 6,
                    color, fillColor: color, fillOpacity: isPoint ? 0.9 : 0.7,
                }).addTo(map);
                const _popupThumb = item.type === 'point' ? item.image : item.specimen_image;
                const img = _popupThumb ? `<img src="${escHtml(_popupThumb)}" style="width:100%;max-height:120px;object-fit:contain;background:#f8f9fa;border-radius:4px;margin-bottom:6px;" onerror="this.style.display='none'"><br>` : '';
                marker.bindPopup(`${img}<strong>${escHtml(item.name||'—')}</strong><br><small>${escHtml(item.date_label||'')}</small>`, { maxWidth: 260 });
                marker.on('click', () => scrollToRow(item.id));
                markers[item.id] = marker;
                allPoints.push([item.lat, item.lon]);
                // Only items with a real date can be placed in the chronology —
                // undated items would otherwise all land on the same fallback
                // sort position and connect to each other in arbitrary order.
                if (item.date_label) datedPoints.push([item.lat, item.lon]);
            });

            // Route line follows the chronology (itinerary points + dated specimens).
            drawGeoPolyline(map, datedPoints, { color: '#6c757d', weight: 1.5, opacity: 0.4 });
            setTimeout(() => {
                map.invalidateSize();
                allPoints.length > 0 ? map.fitBounds(allPoints, { padding: [30, 30] }) : map.setView([20, 0], 2);
            }, 200);
        })
        .catch(e => {
            document.getElementById('itemsLoading').textContent = 'Failed to load items.';
            console.error(e);
        });

</script>
@include('partials.specimen-modal')
@include('partials.wd-entity-popover')
@include('partials.wiki-modal')
@endsection
