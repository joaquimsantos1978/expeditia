@extends('layouts.app')

@section('title', ($institutionInfo['name'] ?? $code))

@section('styles')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<style>
    @include('partials.map-styles')
    .expedition-badge { display:inline-block; width:12px; height:12px; border-radius:50%; margin-right:6px; flex-shrink:0; }
</style>
@endsection

@section('content')

{{-- Header --}}
<div class="row mb-4 align-items-start">
    <div class="col">
        <h1 class="h2 mb-1">
            🏛 {{ $institutionInfo['name'] ?? $code }}
            @if($institutionInfo && $institutionInfo['name'] !== $code)
                <small class="text-muted fs-5">({{ $code }})</small>
            @endif
        </h1>

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <a href="{{ route('institutions.export.csv',  urlencode($code)) }}" class="btn btn-sm btn-outline-secondary">⬇ CSV</a>
            <a href="{{ route('institutions.export.json', urlencode($code)) }}" class="btn btn-sm btn-outline-secondary">⬇ JSON</a>
            <a href="{{ url('/api/institution/' . urlencode($code)) }}" target="_blank" class="btn btn-sm btn-outline-secondary">API ↗</a>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-2 text-muted small">
            @if($institutionInfo['country'] ?? null)
            <span>🌍 {{ $institutionInfo['country'] }}@if($institutionInfo['city'] ?? null), {{ $institutionInfo['city'] }}@endif</span>
            @endif
            @if($institutionInfo['homepage'] ?? null)
            <a href="{{ $institutionInfo['homepage'] }}" target="_blank" class="text-muted">🔗 Website ↗</a>
            @endif
            @if($institutionInfo['gbif_key'] ?? null)
            <a href="https://www.gbif.org/grscicoll/institution/{{ $institutionInfo['gbif_key'] }}" target="_blank" class="text-muted">GBIF GRSciColl ↗</a>
            @endif
        </div>

        <p class="text-muted mb-1">
            <strong>{{ number_format($totalSpecimens) }}</strong> specimens
            · <strong>{{ $expeditions->count() }}</strong> expeditions
            · <strong>{{ number_format($totalMapItems) }}</strong> mapped
        </p>
        <a href="{{ route('institutions.index') }}" class="small text-muted">← All institutions</a>
    </div>
</div>

{{-- Collections --}}
@if($collections->count())
<div class="card mb-3">
    <div class="card-header"><strong>Collections</strong></div>
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-2">
            @foreach($collections as $col)
            <span class="institution-badge px-2 py-1" style="font-size:.85rem;">
                {{ $col->collection_code }}
                <span class="text-muted">· {{ number_format($col->specimens_count) }}</span>
                @if($col->images_count > 0)<span class="ms-1">🖼</span>@endif
            </span>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Map --}}
@include('partials.map')

{{-- List --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex flex-wrap gap-2 align-items-center" id="expeditionLegend"></div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted small" id="itemsCount">{{ $totalMapItems }} items</span>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="btnPrev">←</button>
                <span class="btn btn-outline-secondary disabled" id="navLabel" style="min-width:60px;">— / —</span>
                <button class="btn btn-outline-secondary" id="btnNext">→</button>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="list-group list-group-flush" id="itemsList"
             style="max-height:500px;overflow-y:auto;overflow-x:hidden;">
            <div class="text-center text-muted py-4 small" id="itemsLoading">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading…
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@include('partials.map-scripts')
<script>
    const COLORS = [
        '#e63946','#2a9d8f','#e9c46a','#f4a261','#264653',
        '#6a4c93','#1982c4','#8ac926','#ff595e','#6a994e'
    ];

    const CODE     = '{{ urlencode($code) }}';
    const EXPS     = @json($expeditionsData);
    const markers  = {};
    const allPoints = [];
    const itemCache = {};
    let   allItems  = [];
    const PAGE      = 2000;

    const map = L.map('map');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(map);

    // Build legend
    const legend = document.getElementById('expeditionLegend');
    EXPS.forEach((exp, i) => {
        const color = COLORS[i % COLORS.length];
        const a = document.createElement('a');
        a.href = `/expedition/${exp.qid}`;
        a.className = 'small d-flex align-items-center text-decoration-none me-2';
        a.style.color = color;
        a.innerHTML = `<span class="expedition-badge" style="background:${color};"></span>${exp.label}`;
        legend.appendChild(a);
    });

    function escHtml(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');
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
            : `<span style="font-size:14px;opacity:.4;">🔬</span>`;
        const img = item.specimen_image
            ? `<img src="${escHtml(item.specimen_image)}" style="width:52px;height:52px;object-fit:cover;border-radius:6px;flex-shrink:0;cursor:pointer;" onclick="event.stopPropagation();loadAndShowModal(${item.id})" onerror="this.style.display='none'" loading="lazy">`
            : '';
        const loc = [item.verbatim_locality, item.locality].filter(Boolean).filter((v,i,a)=>a.indexOf(v)===i);
        const locHtml = loc.length ? `<p class="small text-muted mb-0">📍 ${escHtml(loc.join(' · '))}</p>` : '';
        const collHtml = item.collection_code ? `<span class="institution-badge">${escHtml(item.collection_code)}</span>` : '';
        const noCoords = !item.lat ? `<span class="badge bg-light text-muted" style="font-size:10px;">no coordinates</span>` : '';
        const gbifLink = item.gbif_id ? `<a href="https://www.gbif.org/occurrence/${item.gbif_id}" target="_blank" class="small text-muted text-decoration-none" onclick="event.stopPropagation()">GBIF ↗</a>` : '';
        const detailsBtn = `<div class="d-flex flex-wrap align-items-center gap-2 mt-1">${collHtml}${noCoords}${gbifLink}<button class="btn btn-link btn-sm p-0 small text-muted details-btn" onclick="event.stopPropagation();loadAndShowModal(${item.id})" disabled><span class="spinner-border spinner-border-sm"></span></button></div>`;
        return `<div class="list-group-item d-flex align-items-start gap-2 py-2 map-item-row${!item.lat?' no-coords':''}" id="item-${item.id}" data-id="${item.id}" data-type="specimen" data-lat="${item.lat||''}" data-lon="${item.lon||''}" data-exp-index="${item.exp_index}" style="cursor:${item.lat?'pointer':'default'}">
            <div class="mt-1 flex-shrink-0">${hasDot}</div>
            <div class="flex-grow-1" style="min-width:0;">
                <div class="d-flex justify-content-between gap-2">
                    <strong class="small text-truncate">${escHtml(item.name||'—')}</strong>
                    <span class="text-muted small text-nowrap">${escHtml(item.date_label||'')}</span>
                </div>
                ${locHtml}${detailsBtn}
            </div>
            ${img}
        </div>`;
    }

    async function loadAndShowModal(id) {
        const modal  = document.getElementById('specimenModal');
        const isOpen = modal.classList.contains('show');
        if (!itemCache[id]) {
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
                const r = await fetch(`/institution/${CODE}/item/${id}`);
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
        const row = document.getElementById(`item-${id}`);
        if (row) {
            row.classList.add('active');
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            currentIndex = getVisibleRows().indexOf(row);
            updateNavLabel();
        }
    }

    setTimeout(() => { map.invalidateSize(); map.setView([20, 0], 2); }, 200);

    fetch(`/institution/${CODE}/map-data`)
        .then(r => r.json())
        .then(items => {
            allItems = items; window.allItems = items;
            document.getElementById('itemsLoading')?.remove();

            // Render list with expedition headers
            const list = document.getElementById('itemsList');
            let lastExpIndex = -1;
            const frag = document.createDocumentFragment();

            items.slice(0, PAGE).forEach(item => {
                if (item.exp_index !== lastExpIndex) {
                    const exp   = EXPS[item.exp_index];
                    const color = COLORS[item.exp_index % COLORS.length];
                    const hdr   = document.createElement('div');
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
            list.appendChild(frag);
            updateNavLabel();

            // Prefetch visible specimens
            const prefetchObserver = new IntersectionObserver(entries => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    const row = entry.target;
                    const id  = parseInt(row.dataset.id);
                    if (itemCache[id]) { activateDetailsBtn(row); prefetchObserver.unobserve(row); return; }
                    fetch(`/institution/${CODE}/item/${id}`)
                        .then(r => r.json()).then(d => { itemCache[id] = d; activateDetailsBtn(row); })
                        .catch(() => activateDetailsBtn(row));
                    prefetchObserver.unobserve(row);
                });
            }, { rootMargin: '300px' });

            list.querySelectorAll('.map-item-row[data-type="specimen"]').forEach(row => {
                prefetchObserver.observe(row);
            });

            // Map markers
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
                    renderer, radius: 6, color, fillColor: color, fillOpacity: 0.7,
                }).addTo(map);
                const img = item.specimen_image ? `<img src="${escHtml(item.specimen_image)}" style="width:100%;max-height:100px;object-fit:contain;background:#f8f9fa;border-radius:4px;margin-bottom:6px;"><br>` : '';
                marker.bindPopup(`${img}<strong>${escHtml(item.name||'—')}</strong><br><small>${escHtml(item.date_label||'')}</small>`, { maxWidth: 240 });
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

            // Polylines per expedition — chronological order (itinerary + specimens).
            Object.entries(expPoints).forEach(([idx, pts]) => {
                drawGeoPolyline(map, pts, { color: COLORS[idx % COLORS.length], weight: 1.5, opacity: 0.4 });
            });

            setTimeout(() => {
                map.invalidateSize();
                allPoints.length > 0 ? map.fitBounds(allPoints, { padding: [30, 30] }) : map.setView([20, 0], 2);
            }, 200);
        })
        .catch(e => {
            document.getElementById('itemsLoading').textContent = 'Failed to load specimens.';
            console.error(e);
        });
</script>
@include('partials.specimen-modal')
@include('partials.wiki-modal')
@endsection
