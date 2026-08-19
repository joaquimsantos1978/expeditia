@extends('layouts.app')

@section('title', $person->name)

@section('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    @include('partials.map-styles')
    .expedition-badge { display:inline-block; width:12px; height:12px; border-radius:50%; margin-right:6px; flex-shrink:0; }
    @media (max-width: 768px) {
        .leaflet-popup-content { font-size: 13px; }
        .person-photo { width:100px !important; height:100px !important; }
    }
</style>
@endsection

@section('content')

{{-- Header --}}
<div class="row mb-4">
    <div class="col-md-2 text-center mb-3 mb-md-0">
        @if($person->image)
        <img src="{{ $person->image }}"
             class="rounded-circle mx-auto d-block person-photo"
             style="width:140px;height:140px;object-fit:cover;"
             onerror="this.style.display='none'">
        @endif
    </div>
    <div class="col-md-10">
        <h1 class="h2">{{ $person->name }}</h1>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <a href="{{ route('people.export.csv',  $person->wikidata_qid) }}" class="btn btn-sm btn-outline-secondary">⬇ CSV</a>
            <a href="{{ route('people.export.json', $person->wikidata_qid) }}" class="btn btn-sm btn-outline-secondary">⬇ JSON</a>
            <a href="{{ url('/api/person/' . $person->wikidata_qid . '/specimens') }}" target="_blank" class="btn btn-sm btn-outline-secondary">API ↗</a>
            <button class="btn btn-sm btn-outline-secondary"
                    onclick="startSync('{{ route('sync.person', $person->id) }}', this)">
                <i class="bi bi-arrow-clockwise"></i> Sync
            </button>
        </div>
        @include('partials.sync-panel')

        @if($person->description)
        <p class="lead text-muted">{{ $person->description }}</p>
        @endif

        {{-- Key metadata inline --}}
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3 text-muted small">
            @if($person->date_birth)
            <span>
                <i class="bi bi-calendar-event me-1"></i>
                <strong class="text-dark">{{ $person->date_birth }}</strong>
                @if($person->place_birth) · {{ $person->place_birth }} @endif
                @if($person->date_death)
                    – <strong class="text-dark">{{ $person->date_death }}</strong>
                    @if($person->place_death) · {{ $person->place_death }} @endif
                @endif
            </span>
            @endif
            @if($person->occupation)
            <span>
                <i class="bi bi-briefcase me-1"></i>{{ $person->occupation }}
            </span>
            @endif
        </div>

        {{-- External links as buttons --}}
        <div class="d-flex flex-wrap gap-2 mb-3">
            <a href="https://www.wikidata.org/wiki/{{ $person->wikidata_qid }}"
               target="_blank" class="btn btn-sm btn-outline-secondary">
                <img src="https://www.wikidata.org/static/favicon/wikidata.ico" width="14" height="14" style="vertical-align:text-bottom;margin-right:3px;" alt="">{{ $person->wikidata_qid }} ↗
            </a>
            @if($person->wikipedia_url)
            <button class="wiki-btn btn btn-sm btn-outline-secondary"
                    data-qid="{{ $person->wikidata_qid }}"
                    data-label="{{ $person->name ?? $person->wikidata_qid }}"
                    data-wp-url="{{ $person->wikipedia_url }}">
                <i class="bi bi-wikipedia"></i> Wikipedia
            </button>
            @endif
            @if($person->orcid)
            <a href="https://orcid.org/{{ $person->orcid }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-person-badge me-1"></i>ORCID ↗
            </a>
            @endif
            @if($person->bionomia_id)
            <a href="https://bionomia.net/{{ $person->bionomia_id }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                <img src="https://commons.wikimedia.org/wiki/Special:FilePath/Logo_for_Bionomia.png" width="16" height="16" style="vertical-align:text-bottom;margin-right:3px;" alt="">Bionomia ↗
            </a>
            @endif
        </div>
    </div>
</div>

@if($person->expeditions->count())

@include('partials.map')

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex flex-wrap gap-2" id="expeditionLegend"></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="btn-group btn-group-sm" id="filterButtons">
                <button class="btn btn-outline-secondary active" data-filter="all">All</button>
                <button class="btn btn-outline-primary" data-filter="point">📍</button>
                <button class="btn btn-outline-success" data-filter="specimen">🔬</button>
            </div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="btnPrev">←</button>
                <span class="btn btn-outline-secondary disabled" id="navLabel" style="min-width:60px;">— / —</span>
                <button class="btn btn-outline-secondary" id="btnNext">→</button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto;overflow-x:hidden;" id="itemsList">
            <div class="text-center text-muted py-4 small" id="itemsLoading">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading items…
            </div>
        </div>
    </div>
</div>

@endif

@endsection

@section('scripts')
@include('partials.map-scripts')
<script>
    const COLORS = [
        '#e63946','#2a9d8f','#e9c46a','#f4a261','#264653',
        '#6a4c93','#1982c4','#8ac926','#ff595e','#6a994e'
    ];
    COLORS.forEach((c, i) => document.documentElement.style.setProperty(`--exp-color-${i}`, c));

    const QID      = '{{ $person->wikidata_qid }}';
    const EXPS     = @json($expeditionsData); // lightweight: [{id,qid,label}]
    const markers  = {};
    const allPoints = [];
    const itemCache = {};
    let   allItems  = [];
    let   rendered  = 0;
    const PAGE      = 2000;
    let   prefetchObserver;

    const map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Build legend
    const legend = document.getElementById('expeditionLegend');
    EXPS.forEach((exp, i) => {
        const color = COLORS[i % COLORS.length];
        const badge = document.createElement('a');
        badge.href = `/expedition/${exp.qid}`;
        badge.className = 'small d-flex align-items-center text-decoration-none';
        badge.style.color = color;
        badge.innerHTML = `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};margin-right:4px;flex-shrink:0;"></span>${exp.label}`;
        legend.appendChild(badge);
    });

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

    function activateDetailsBtn(row) {
        const btn = row.querySelector('.details-btn');
        if (btn) { btn.disabled = false; btn.innerHTML = '🔍 Details'; }
    }

    function renderPlaceholder(item) {
        const color  = COLORS[item.exp_index % COLORS.length];
        const hasDot = item.lat
            ? `<span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};flex-shrink:0;"></span>`
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
        const institutionBadge = item.institution_code
            ? `<span class="institution-badge">${escHtml(item.institution_code)}${item.collection_code ? ' / ' + escHtml(item.collection_code) : ''}</span>`
            : '';
        const detailsBtn = item.type==='specimen'
            ? `<div class="d-flex flex-wrap align-items-center gap-2 mt-1">${institutionBadge}${noCoords}${gbifLink}<button class="btn btn-link btn-sm p-0 small text-muted details-btn" onclick="event.stopPropagation();loadAndShowModal(${item.id})" disabled><span class="spinner-border spinner-border-sm"></span></button></div>`
            : '';
        return `<div class="list-group-item d-flex align-items-start gap-2 py-2 map-item-row${!item.lat?' no-coords':''}" id="item-${item.id}" data-id="${item.id}" data-type="${escHtml(item.type)}" data-lat="${item.lat||''}" data-lon="${item.lon||''}" data-exp-index="${item.exp_index}" style="cursor:${item.lat?'pointer':'default'}">
            <div class="mt-1 flex-shrink-0">${hasDot}</div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="d-flex justify-content-between gap-2">
                    <strong class="small text-truncate">${escHtml(item.name||'—')}</strong>
                    <span class="text-muted small text-nowrap">${escHtml(item.date_label||'')}</span>
                </div>
                ${locHtml}${collectorHtml}${detailsBtn}
            </div>
            ${img}
        </div>`;
    }

    async function loadAndShowModal(id) {
        const modal  = document.getElementById('specimenModal');
        const isOpen = modal.classList.contains('show');
        if (!itemCache[id]) {
            if (isOpen) {
                document.getElementById('specimenModalTitle').textContent = '…';
                document.getElementById('specimenModalFamily').textContent = '';
                document.getElementById('specimenModalImageCol').classList.add('d-none');
                document.getElementById('specimenModalGbif').style.display = 'none';
                document.getElementById('specimenMiniMapWrap').style.display = 'none';
                document.getElementById('specimenNoMapWrap').style.display = 'none';
                document.getElementById('specimenModalFields').innerHTML =
                    '<tr><td colspan="2" class="text-center py-5"><div class="spinner-border text-muted" role="status"></div></td></tr>';
            }
            try {
                const r = await fetch(`/person/${QID}/item/${id}`);
                itemCache[id] = await r.json();
            } catch(e) { console.error(e); return; }
        }
        const d = itemCache[id];
        const dataset = {
            itemId:id,
            image:d.specimen_image||'',name:d.name||'',scientific:d.scientific_name||'',
            family:d.family||'',kingdom:d.kingdom||'',locality:d.locality||'',
            verbatim:d.verbatim_locality||'',
            higherGeography:d.higher_geography||'',stateProvince:d.state_province||'',
            county:d.county||'',municipality:d.municipality||'',
            date:d.event_date||'',year:String(d.year||''),
            country:d.country||'',countryCode:d.country_code||'',recorded:d.recorded_by||'',
            identified:d.identified_by||'',dateIdentified:d.date_identified||'',
            typeStatus:d.type_status||'',institution:d.institution_code||'',
            collection:d.collection_code||'',catalog:d.catalog_number||'',
            basis:d.basis_of_record||'',license:d.license||'',occurrence:d.occurrence_id||'',
            gbif:String(d.gbif_id||''),lat:String(d.lat||''),lon:String(d.lon||''),
            coord_uncertainty_m: d.coord_uncertainty_m || null,
        };
        const rows = Array.from(document.querySelectorAll('#itemsList .map-item-row:not(.d-none)[data-type="specimen"]'));
        modalSpecimenRows = rows;
        modalCurrentIndex = rows.findIndex(r => parseInt(r.dataset.id) === id);
        if (isOpen) { fillModal(dataset); updateModalNavLabel(); }
        else { fillModal(dataset); updateModalNavLabel(); new bootstrap.Modal(modal).show(); }
    }

    function scrollToRow(id) {
        document.querySelectorAll('.map-item-row').forEach(r => r.classList.remove('active'));
        function tryScroll() {
            const row = document.getElementById(`item-${id}`);
            if (row) { row.classList.add('active'); row.scrollIntoView({ behavior:'smooth', block:'center' }); return; }
            if (typeof window.renderNextBatch === 'function' && !window._renderDone) {
                window.renderNextBatch(); setTimeout(tryScroll, 0);
            }
        }
        tryScroll();
    }

    function observeNewRows() {
        prefetchObserver = prefetchObserver || new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const row = entry.target;
                const id  = parseInt(row.dataset.id);
                if (row.dataset.type !== 'specimen' || itemCache[id]) {
                    activateDetailsBtn(row); prefetchObserver.unobserve(row); return;
                }
                fetch(`/person/${QID}/item/${id}`)
                    .then(r => r.json()).then(d => { itemCache[id] = d; activateDetailsBtn(row); })
                    .catch(() => activateDetailsBtn(row));
                prefetchObserver.unobserve(row);
            });
        }, { rootMargin: '300px' });
        document.querySelectorAll('#itemsList .map-item-row[data-type="specimen"]:not([data-observed])').forEach(row => {
            row.dataset.observed = '1'; prefetchObserver.observe(row);
        });
    }

    window.renderNextBatch = renderNextBatch;
    window._renderDone = false;
    function renderNextBatch() {
        // Group items by expedition for headers
        const batch = allItems.slice(rendered, rendered + PAGE);
        if (!batch.length) return;
        const list = document.getElementById('itemsList');
        let lastExpIndex = rendered > 0 ? allItems[rendered - 1].exp_index : -1;
        const frag = document.createElement('div');
        batch.forEach(item => {
            if (item.exp_index !== lastExpIndex) {
                const exp = EXPS[item.exp_index];
                const color = COLORS[item.exp_index % COLORS.length];
                const hdr = document.createElement('div');
                hdr.className = 'list-group-item bg-light py-1 px-3 exp-header';
                hdr.dataset.expIndex = item.exp_index;
                hdr.innerHTML = `<small class="fw-bold"><a href="/expedition/${exp.qid}" class="text-decoration-none" style="color:${color}"><span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${color};margin-right:4px;"></span>${exp.label}</a></small>`;
                frag.appendChild(hdr);
                lastExpIndex = item.exp_index;
            }
            const div = document.createElement('div');
            div.innerHTML = renderPlaceholder(item);
            frag.appendChild(div.firstChild);
        });
        while (frag.firstChild) list.appendChild(frag.firstChild);
        rendered += batch.length;
        observeNewRows();
        let btn = document.getElementById('loadMoreBtn');
        if (rendered < allItems.length) {
            if (!btn) {
                btn = document.createElement('button');
                btn.id = 'loadMoreBtn'; btn.className = 'btn btn-outline-secondary btn-sm w-100 my-2';
                btn.onclick = renderNextBatch; list.after(btn);
            }
            btn.textContent = `Load more (${allItems.length - rendered} remaining)`;
        } else {
            if (btn) { btn.remove(); window._renderDone = true; }
        }
    }

    // Init map size immediately
    setTimeout(() => { map.invalidateSize(); map.setView([20, 0], 2); }, 200);

    fetch(`/person/${QID}/map-data`)
        .then(r => r.json())
        .then(items => {
            allItems = items; window.allItems = items;
            document.getElementById('itemsLoading')?.remove();
            renderNextBatch();
            applyFilter(currentFilter);

            // Map: canvas renderer, color per expedition
            const renderer = L.canvas({ padding: 0.5 });
            const expPoints = {};

            items.forEach(item => {
                if (!item.lat || !item.lon) return;
                const color = COLORS[item.exp_index % COLORS.length];

                // Coordinate uncertainty radius (metres) — how much to trust the pin.
                if (item.coord_uncertainty_m) {
                    L.circle([item.lat, item.lon], {
                        renderer, radius: item.coord_uncertainty_m,
                        color, weight: 1, fillColor: color, fillOpacity: 0.08,
                        interactive: false,
                    }).addTo(map);
                }

                const marker = L.circleMarker([item.lat, item.lon], {
                    renderer, radius: item.type==='point' ? 10 : 6,
                    color, fillColor: color, fillOpacity: item.type==='point' ? 0.9 : 0.7,
                }).addTo(map);
                const exp = EXPS[item.exp_index];
                marker.bindPopup(`<strong>${escHtml(item.name||'—')}</strong><br><small style="color:${color}">${exp?.label||''}</small><br><small>${escHtml(item.date_label||'')}</small>`, { maxWidth: 260 });
                marker.on('click', () => scrollToRow(item.id));
                markers[item.id] = marker;
                allPoints.push([item.lat, item.lon]);
                // Only items with a real date can be placed in the chronology —
                // undated items would otherwise all land on the same fallback
                // sort position and connect to each other in arbitrary order.
                if (item.date_label) {
                    if (!expPoints[item.exp_index]) expPoints[item.exp_index] = [];
                    expPoints[item.exp_index].push([item.lat, item.lon]);
                }
            });

            // Polylines per expedition
            Object.entries(expPoints).forEach(([idx, pts]) => {
                drawGeoPolyline(map, pts, { color: COLORS[idx % COLORS.length], weight: 1.5, opacity: 0.4 });
            });

            setTimeout(() => {
                map.invalidateSize();
                allPoints.length > 0 ? map.fitBounds(allPoints, { padding: [30, 30] }) : map.setView([20, 0], 2);
            }, 200);
        })
        .catch(e => {
            const el = document.getElementById('itemsLoading');
            if (el) el.textContent = 'Failed to load items.';
            console.error(e);
        });
</script>
@include('partials.specimen-modal')
@include('partials.wiki-modal')
@endsection
