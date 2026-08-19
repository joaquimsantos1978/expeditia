{{-- Specimen Modal --}}
<div class="modal fade" id="specimenModal" tabindex="-1">
    {{--
        modal-fullscreen-sm-down : fullscreen on phones (< 576px)
        modal-xl                 : large on desktop
    --}}
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content" style="height:90vh;">

            <div class="modal-header py-2">
                <div class="flex-grow-1 me-2" style="min-width:0;">
                    <h6 class="modal-title fst-italic mb-0 text-truncate" id="specimenModalTitle"></h6>
                    <small class="text-muted" id="specimenModalFamily"></small>
                </div>
                <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="modal"></button>
            </div>

            {{--
                Modal body uses flexbox:
                - Desktop (row): image panel (58%) | details panel (42%)
                - Mobile (column): image (max 38vh) stacked above scrollable details
            --}}
            <div class="modal-body p-0" id="specimenModalBody"
                 style="flex:1;min-height:0;display:flex;flex-direction:row;overflow:hidden;">

                {{-- Image panel --}}
                <div id="specimenModalImageCol" class="d-none flex-shrink-0"
                     style="background:#1a1a1a;position:relative;flex:0 0 58%;overflow:hidden;">
                    <div style="position:absolute;top:10px;right:10px;z-index:10;display:flex;flex-direction:column;gap:4px;">
                        <button onclick="zoomIn()"    class="btn btn-sm btn-dark opacity-75">+</button>
                        <button onclick="zoomOut()"   class="btn btn-sm btn-dark opacity-75">−</button>
                        <button onclick="zoomReset()" class="btn btn-sm btn-dark opacity-75" style="font-size:10px;">↺</button>
                    </div>
                    <div id="specimenImageContainer"
                         style="width:100%;height:100%;overflow:hidden;cursor:grab;display:flex;align-items:center;justify-content:center;">
                        <img id="specimenModalImage" src=""
                             style="max-width:100%;max-height:100%;object-fit:contain;transform-origin:center;transition:transform 0.1s;user-select:none;"
                             draggable="false">
                    </div>
                </div>

                {{-- Details + mini-map panel --}}
                <div id="specimenModalDetails"
                     class="d-flex flex-column flex-grow-1" style="min-width:0;overflow:hidden;">

                    {{-- Scrollable fields --}}
                    <div class="overflow-auto p-3 flex-grow-1">
                        <table class="table table-sm table-borderless mb-0 w-100">
                            <tbody id="specimenModalFields"></tbody>
                        </table>
                    </div>

                    {{-- Mini map --}}
                    <div id="specimenMiniMapWrap" style="height:200px;flex-shrink:0;display:none;">
                        <div id="specimenMiniMap" style="height:100%;width:100%;"></div>
                    </div>
                    <div id="specimenNoMapWrap"
                         style="height:64px;flex-shrink:0;display:none;background:#f8f9fa;">
                        <div class="h-100 d-flex align-items-center justify-content-center text-muted small">
                            No coordinates available
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex justify-content-between">
                <div class="btn-group">
                    <button class="btn btn-outline-secondary" onclick="modalNavigate(-1)" style="min-width:56px;">← Prev</button>
                    <span class="btn btn-outline-secondary disabled" id="modalNavLabel" style="min-width:72px;font-size:13px;"></span>
                    <button class="btn btn-outline-secondary" onclick="modalNavigate(1)"  style="min-width:56px;">Next →</button>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" id="specimenModalRefresh" class="btn btn-sm btn-outline-secondary"
                            style="display:none;" onclick="refreshCurrentSpecimen()"
                            title="Re-fetch this specimen from GBIF and georeference.it">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                    <a id="specimenModalGbif" href="" target="_blank"
                       class="btn btn-sm btn-outline-secondary" style="display:none;">GBIF ↗</a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">✕ Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Mobile: stack image above details */
@media (max-width: 575px) {
    #specimenModalBody        { flex-direction: column !important; }
    #specimenModalImageCol    { flex: 0 0 36vh !important; width: 100% !important; }
    #specimenMiniMapWrap      { height: 160px !important; }
    #specimenModal th         { width: 110px !important; }
}
#specimenModal td { word-break: break-word; white-space: normal; }
#specimenModal th { white-space: nowrap; vertical-align: top; width: 140px; }
#specimenImageContainer:active { cursor: grabbing; }
</style>

<script>
// ── Zoom & Pan ────────────────────────────────────────────────────────────────
let zoomScale = 1, panX = 0, panY = 0;
let isDragging = false, startX, startY;

function applyTransform() {
    document.getElementById('specimenModalImage').style.transform =
        `translate(${panX}px,${panY}px) scale(${zoomScale})`;
}
function zoomIn()    { zoomScale = Math.min(zoomScale * 1.3, 8); applyTransform(); }
function zoomOut()   { zoomScale = Math.max(zoomScale / 1.3, 0.5); applyTransform(); }
function zoomReset() { zoomScale = 1; panX = 0; panY = 0; applyTransform(); }

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('specimenImageContainer');
    if (!container) return;

    container.addEventListener('wheel', e => { e.preventDefault(); e.deltaY < 0 ? zoomIn() : zoomOut(); }, { passive: false });

    container.addEventListener('mousedown', e => { isDragging = true; startX = e.clientX - panX; startY = e.clientY - panY; container.style.cursor = 'grabbing'; });
    document.addEventListener('mousemove', e => { if (!isDragging) return; panX = e.clientX - startX; panY = e.clientY - startY; applyTransform(); });
    document.addEventListener('mouseup',   () => { isDragging = false; const c = document.getElementById('specimenImageContainer'); if(c) c.style.cursor = 'grab'; });

    let lastDist = null;
    container.addEventListener('touchstart', e => {
        if (e.touches.length === 2) {
            lastDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
        } else { isDragging = true; startX = e.touches[0].clientX - panX; startY = e.touches[0].clientY - panY; }
    });
    container.addEventListener('touchmove', e => {
        e.preventDefault();
        if (e.touches.length === 2) {
            const dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
            if (lastDist) { zoomScale = Math.min(Math.max(zoomScale * (dist / lastDist), 0.5), 8); applyTransform(); }
            lastDist = dist;
        } else if (isDragging) { panX = e.touches[0].clientX - startX; panY = e.touches[0].clientY - startY; applyTransform(); }
    }, { passive: false });
    container.addEventListener('touchend', () => { isDragging = false; lastDist = null; });
});

document.getElementById('specimenModal').addEventListener('hidden.bs.modal', zoomReset);
document.getElementById('specimenModal').addEventListener('shown.bs.modal', function () {
    if (!miniMap || miniMap._pendingLat == null) return;
    miniMap.invalidateSize();
    if (!miniMapInitialised) { miniMap.setView([miniMap._pendingLat, miniMap._pendingLon], 8); miniMapInitialised = true; }
    else miniMap.panTo([miniMap._pendingLat, miniMap._pendingLon]);
    if (miniMarker) miniMarker.remove();
    if (miniUncertaintyCircle) miniUncertaintyCircle.remove();
    if (miniMap._pendingUncertainty) {
        miniUncertaintyCircle = L.circle([miniMap._pendingLat, miniMap._pendingLon], {
            radius: miniMap._pendingUncertainty, color: '#198754', weight: 1, fillColor: '#198754', fillOpacity: 0.08, interactive: false,
        }).addTo(miniMap);
    }
    miniMarker = L.circleMarker([miniMap._pendingLat, miniMap._pendingLon], { radius:8, color:'#198754', fillColor:'#198754', fillOpacity:0.9 }).addTo(miniMap);
    miniMap._pendingLat = null;
});

// ── Mini map ──────────────────────────────────────────────────────────────────
let miniMap = null, miniMarker = null, miniUncertaintyCircle = null, miniMapInitialised = false;

function initMiniMap() {
    if (miniMap) return;
    miniMap = L.map('specimenMiniMap', { zoomControl: true, scrollWheelZoom: true, attributionControl: false });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(miniMap);
}

function updateMiniMap(lat, lon, uncertaintyM) {
    const mapWrap   = document.getElementById('specimenMiniMapWrap');
    const noMapWrap = document.getElementById('specimenNoMapWrap');
    if (!lat || !lon) { mapWrap.style.display = 'none'; noMapWrap.style.display = 'none'; return; }
    mapWrap.style.display = 'block'; noMapWrap.style.display = 'none';
    initMiniMap();
    miniMap._pendingLat = lat; miniMap._pendingLon = lon; miniMap._pendingUncertainty = uncertaintyM || null;
    if (document.getElementById('specimenModal').classList.contains('show')) {
        setTimeout(() => {
            miniMap.invalidateSize();
            if (!miniMapInitialised) { miniMap.setView([lat, lon], 8); miniMapInitialised = true; }
            else miniMap.panTo([lat, lon]);
            if (miniMarker) miniMarker.remove();
            if (miniUncertaintyCircle) miniUncertaintyCircle.remove();
            if (uncertaintyM) {
                miniUncertaintyCircle = L.circle([lat, lon], {
                    radius: uncertaintyM, color: '#198754', weight: 1, fillColor: '#198754', fillOpacity: 0.08, interactive: false,
                }).addTo(miniMap);
            }
            miniMarker = L.circleMarker([lat, lon], { radius:8, color:'#198754', fillColor:'#198754', fillOpacity:0.9 }).addTo(miniMap);
        }, 50);
    }
}

// ── Keyboard navigation ───────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('specimenModal');
    if (!modal.classList.contains('show')) return;
    if (e.key === 'ArrowRight') { e.preventDefault(); modalNavigate(1);  }
    if (e.key === 'ArrowLeft')  { e.preventDefault(); modalNavigate(-1); }
});

// ── Modal navigation ──────────────────────────────────────────────────────────
let modalSpecimenRows = [], modalCurrentIndex = -1;

function modalNavigate(direction) {
    if (!modalSpecimenRows.length) return;

    // Refresh rows in case more have been rendered
    const fresh = Array.from(document.querySelectorAll('#itemsList .map-item-row:not(.d-none)[data-type="specimen"]'));
    if (fresh.length > modalSpecimenRows.length) modalSpecimenRows = fresh;

    const next = modalCurrentIndex + direction;

    // If going forward past the end and more items exist, render next batch first
    if (next >= modalSpecimenRows.length && typeof window.renderNextBatch === 'function') {
        window.renderNextBatch();
        const updated = Array.from(document.querySelectorAll('#itemsList .map-item-row:not(.d-none)[data-type="specimen"]'));
        if (updated.length > modalSpecimenRows.length) modalSpecimenRows = updated;
    }

    modalCurrentIndex = (next + modalSpecimenRows.length) % modalSpecimenRows.length;
    const row = modalSpecimenRows[modalCurrentIndex];

    document.querySelectorAll('.map-item-row').forEach(r => r.classList.remove('active'));
    row.classList.add('active');
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Lazy-loaded pages use loadAndShowModal; others use inline data attributes
    if (typeof loadAndShowModal === 'function') {
        loadAndShowModal(parseInt(row.dataset.id));
    } else {
        const btn = row.querySelector('[onclick*="showSpecimenModal"]');
        if (btn) fillModal(btn.dataset);
        updateModalNavLabel();
    }
}

function updateModalNavLabel() {
    const total = window.allItems
        ? window.allItems.filter(i => i.type === 'specimen').length
        : modalSpecimenRows.length;
    document.getElementById('modalNavLabel').textContent = `${modalCurrentIndex + 1} / ${total}`;
}

// ── Show modal ────────────────────────────────────────────────────────────────
function showSpecimenModal(btn) {
    modalSpecimenRows = Array.from(document.querySelectorAll('.map-item-row:not(.d-none)[data-type="specimen"]'));
    modalCurrentIndex = modalSpecimenRows.indexOf(btn.closest('.map-item-row'));
    fillModal(btn.dataset);
    updateModalNavLabel();
    new bootstrap.Modal(document.getElementById('specimenModal')).show();
}

let currentModalData = null;

function fillModal(d) {
    currentModalData = d;
    zoomReset();
    document.getElementById('specimenModalTitle').textContent  = d.scientific || d.name || '—';
    document.getElementById('specimenModalFamily').textContent = [d.family, d.kingdom].filter(Boolean).join(' · ');

    // Image
    const imgCol  = document.getElementById('specimenModalImageCol');
    const img     = document.getElementById('specimenModalImage');

    if (d.image) {
        img.src = d.image;
        imgCol.classList.remove('d-none');
    } else {
        imgCol.classList.add('d-none');
    }

    // Fields
    const coordValue = (d.lat && d.lon)
        ? `${parseFloat(d.lat).toFixed(5)}, ${parseFloat(d.lon).toFixed(5)}`
        : null;

    const fields = [
        { label: 'Locality',          value: d.locality },
        { label: 'Verbatim locality', value: d.verbatim },
        { label: 'Higher geography',  value: d.higherGeography },
        { label: 'State/Province',    value: d.stateProvince },
        { label: 'County',            value: d.county },
        { label: 'Municipality',      value: d.municipality },
        { label: 'Country',           value: [d.country, d.countryCode].filter(Boolean).join(' · ') },
        { label: 'Coordinates',       value: coordValue },
        { label: 'Date',              value: d.date },
        { label: 'Recorded by',       value: d.recorded },
        { label: 'Identified by',     value: d.identified },
        { label: 'Date identified',   value: d.dateIdentified },
        { label: 'Type status',       value: d.typeStatus },
        { label: 'Institution',       value: d.institution },
        { label: 'Collection',        value: d.collection },
        { label: 'Catalog number',    value: d.catalog },
        { label: 'Basis of record',   value: d.basis },
        { label: 'Occurrence ID',     value: d.occurrence },
        { label: 'License',           value: d.license },
    ];

    const tbody = document.getElementById('specimenModalFields');
    tbody.innerHTML = '';
    fields.forEach(f => {
        if (!f.value) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `<th>${f.label}</th><td>${f.value}</td>`;
        tbody.appendChild(tr);
    });

    // GBIF link
    const gbifLink = document.getElementById('specimenModalGbif');
    if (d.gbif) { gbifLink.href = `https://www.gbif.org/occurrence/${d.gbif}`; gbifLink.style.display = ''; }
    else          { gbifLink.style.display = 'none'; }

    const refreshBtn = document.getElementById('specimenModalRefresh');
    refreshBtn.style.display = d.gbif ? '' : 'none';
    refreshBtn.disabled = false;
    refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh';

    updateMiniMap(d.lat || null, d.lon || null, d.coord_uncertainty_m || null);
}

// ── Refresh a single specimen from GBIF + georeference.it ──────────────────────
// Corrects one record without resyncing the whole (potentially 2000-specimen)
// expedition. Patches the open modal in place from the endpoint's response,
// and updates the page's item cache (if present) so navigating away and back
// shows the fresh data without a refetch.
async function refreshCurrentSpecimen() {
    if (!currentModalData || !currentModalData.gbif) return;
    const gbifId = currentModalData.gbif;
    const itemId = currentModalData.itemId;

    const btn = document.getElementById('specimenModalRefresh');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing…';

    try {
        const r = await fetch(`/sync/specimen/${gbifId}/refresh`);
        if (!r.ok) throw new Error('refresh failed: ' + r.status);
        const j = await r.json();

        Object.assign(currentModalData, {
            lat: String(j.lat || ''), lon: String(j.lon || ''),
            coord_uncertainty_m: j.coord_uncertainty_m || null,
            image: j.image || '',
            locality: j.locality || '', verbatim: j.verbatim_locality || '',
            higherGeography: j.higher_geography || '', stateProvince: j.state_province || '',
            county: j.county || '', municipality: j.municipality || '',
            scientific: j.scientific_name || '', family: j.family || '', kingdom: j.kingdom || '',
            institution: j.institution_code || '', collection: j.collection_code || '',
            catalog: j.catalog_number || '', recorded: j.recorded_by || '',
            identified: j.identified_by || '', typeStatus: j.type_status || '',
            country: j.country || '', countryCode: j.country_code || '',
        });

        if (typeof itemCache !== 'undefined' && itemId != null && itemCache[itemId]) {
            Object.assign(itemCache[itemId], {
                lat: j.lat, lon: j.lon, coord_uncertainty_m: j.coord_uncertainty_m,
                specimen_image: j.image, locality: j.locality, verbatim_locality: j.verbatim_locality,
                higher_geography: j.higher_geography, state_province: j.state_province,
                county: j.county, municipality: j.municipality, scientific_name: j.scientific_name,
                family: j.family, kingdom: j.kingdom, institution_code: j.institution_code,
                collection_code: j.collection_code, catalog_number: j.catalog_number,
                recorded_by: j.recorded_by, identified_by: j.identified_by, type_status: j.type_status,
                country: j.country, country_code: j.country_code,
            });
        }

        fillModal(currentModalData);
        btn.innerHTML = '<i class="bi bi-check2"></i> Updated';
        setTimeout(() => { if (btn) { btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh'; } }, 1500);
    } catch (e) {
        console.error('Specimen refresh failed', e);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Refresh'; }, 2000);
    }
}
</script>
