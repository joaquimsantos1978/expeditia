<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// Navigation
let currentIndex  = -1;
let currentFilter = 'all';

function getVisibleRows() {
    return Array.from(document.querySelectorAll('#itemsList .map-item-row:not(.d-none)'));
}

function updateNavLabel() {
    const rows = getVisibleRows();
    const el   = document.getElementById('navLabel');
    if (el) el.textContent = currentIndex >= 0 ? `${currentIndex + 1} / ${rows.length}` : `— / ${rows.length}`;
}

function navigateTo(index) {
    const rows = getVisibleRows();
    if (rows.length === 0) return;
    if (index < 0) index = rows.length - 1;
    if (index >= rows.length) index = 0;
    currentIndex = index;
    const row = rows[currentIndex];
    document.querySelectorAll('.map-item-row').forEach(r => r.classList.remove('active'));
    row.classList.add('active');
    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const lat = row.dataset.lat, lon = row.dataset.lon;
    if (lat && lon) { map.panTo([lat, lon], { animate: true }); if (markers[row.dataset.id]) markers[row.dataset.id].openPopup(); }
    updateNavLabel();
}

document.getElementById('btnPrev')?.addEventListener('click', () => navigateTo(currentIndex - 1));
document.getElementById('btnNext')?.addEventListener('click', () => navigateTo(currentIndex + 1));

document.addEventListener('keydown', function(e) {
    if (document.getElementById('specimenModal')?.classList.contains('show')) return;
    if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { e.preventDefault(); navigateTo(currentIndex + 1); }
    if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   { e.preventDefault(); navigateTo(currentIndex - 1); }
});

// Row clicks via event delegation
document.getElementById('itemsList').addEventListener('click', function(e) {
    const row = e.target.closest('.map-item-row');
    if (!row || e.target.closest('button, a')) return;
    const lat = row.dataset.lat, lon = row.dataset.lon;
    if (!lat || !lon) return;
    document.querySelectorAll('.map-item-row').forEach(r => r.classList.remove('active'));
    row.classList.add('active');
    map.panTo([lat, lon], { animate: true });
    if (markers[row.dataset.id]) markers[row.dataset.id].openPopup();
    currentIndex = getVisibleRows().indexOf(row);
    updateNavLabel();
});

// Filters
function applyFilter(filter) {
    currentFilter = filter;
    document.querySelectorAll('#filterButtons button').forEach(b => b.classList.toggle('active', b.dataset.filter === filter));
    document.querySelectorAll('#itemsList .map-item-row').forEach(row => {
        row.classList.toggle('d-none', filter !== 'all' && row.dataset.type !== filter);
    });
    // Also hide/show expedition headers (people view)
    document.querySelectorAll('#itemsList .exp-header').forEach(hdr => {
        if (filter === 'all') { hdr.classList.remove('d-none'); return; }
        const expIndex = hdr.dataset.expIndex;
        const hasVisible = document.querySelector(
            `#itemsList .map-item-row[data-exp-index="${expIndex}"][data-type="${filter}"]:not(.d-none)`
        );
        hdr.classList.toggle('d-none', !hasVisible);
    });
    Object.keys(markers).forEach(id => {
        const row = document.getElementById(`item-${id}`);
        if (!row) return;
        const show = filter === 'all' || row.dataset.type === filter;
        show ? markers[id].addTo(map) : markers[id].remove();
    });
    currentIndex = -1;
    updateNavLabel();
}

document.querySelectorAll('#filterButtons button').forEach(btn => {
    btn.addEventListener('click', function() { applyFilter(this.dataset.filter); });
});

// ── Coordinate uncertainty radius ───────────────────────────────────────────
// One circle per distinct (coordinate, radius, colour): specimens that share
// exactly the same location AND radius draw the circle once — stacking dozens of
// identical translucent fills is what turned the map solid. They still get their
// own pin (see OverlapGroups below). Coordinates arrive from the API as strings
// ("-8.9067790"), so everything is converted with Number() before any arithmetic.
const UncertaintyRadius = (() => {
    const drawn = new Set();
    const layer = L.layerGroup();
    let visible = true;
    let controlAdded = false;

    function addControl() {
        if (controlAdded) return;
        controlAdded = true;
        const ctl = L.control({ position: 'topright' });
        ctl.onAdd = () => {
            const div = L.DomUtil.create('div', 'leaflet-bar');
            div.style.cssText = 'background:#fff;padding:4px 8px;font-size:12px;line-height:1.4;';
            div.innerHTML = '<label style="margin:0;cursor:pointer;"><input type="checkbox" checked style="vertical-align:middle;margin-right:4px;">Show uncertainty radii</label>';
            L.DomEvent.disableClickPropagation(div);
            div.querySelector('input').addEventListener('change', e => {
                visible = e.target.checked;
                if (visible) { layer.addTo(map); layer.eachLayer(c => c.bringToBack()); }
                else layer.remove();
            });
            return div;
        };
        ctl.addTo(map);
    }

    return {
        add(item, color, renderer) {
            const lat = Number(item.lat), lon = Number(item.lon), r = Number(item.coord_uncertainty_m);
            if (!isFinite(lat) || !isFinite(lon) || !(r > 0)) return;
            const key = `${lat.toFixed(6)},${lon.toFixed(6)},${r},${color}`;
            if (drawn.has(key)) return;
            drawn.add(key);
            if (visible && !map.hasLayer(layer)) layer.addTo(map);
            L.circle([lat, lon], {
                renderer, radius: r, color, weight: 1, fillColor: color, fillOpacity: 0.08, interactive: false,
            }).addTo(layer);
            addControl();
        },
    };
})();

// ── Specimens at exactly the same coordinates ───────────────────────────────
// Their pins sit on top of each other, so only one could ever be clicked. A
// numbered badge marks such a spot; clicking it (or any pin there, or opening
// one from the list) spreads the group out in a spiral with a thin leg to each
// pin, so every specimen can be clicked individually. Click the badge again,
// click the map, press Esc or zoom to put them back.
const OverlapGroups = (() => {
    const groups = new Map();
    let open = null;
    let ignoreMapClick = false;
    let wired = false;

    function wire() {
        if (wired) return;
        wired = true;
        const pane = map.createPane('spiderLegs');   // below the pins, above the tiles
        pane.style.zIndex = 390;
        map.on('click', () => { if (!ignoreMapClick) collapse(); });
        map.on('zoomstart', collapse);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') collapse(); });
    }

    function swallowMapClick() {
        // Path markers let clicks bubble up to the map; don't treat that as "click on empty map".
        ignoreMapClick = true;
        setTimeout(() => { ignoreMapClick = false; }, 0);
    }

    function expand(g) {
        if (open === g) return;
        collapse();
        const c = map.latLngToLayerPoint([g.lat, g.lon]);
        const legs = L.layerGroup();
        g.members.forEach((m, i) => {
            const rad = 22 + 8.8 * Math.sqrt(i), ang = i * 2.399963;      // Fermat spiral, ~16px apart (pins are ~15px with their outline)
            const ll = map.layerPointToLatLng(L.point(c.x + rad * Math.cos(ang), c.y + rad * Math.sin(ang)));
            m.setLatLng(ll);
            L.polyline([[g.lat, g.lon], ll], { pane: 'spiderLegs', color: '#6c757d', weight: 1, opacity: 0.6, interactive: false }).addTo(legs);
        });
        legs.addTo(map);
        g.legs = legs;
        open = g;
    }

    function collapse() {
        if (!open) return;
        const g = open;
        open = null;
        g.members.forEach(m => m.setLatLng([g.lat, g.lon]));
        g.legs.remove();
        g.legs = null;
    }

    return {
        add(marker, item, color) {
            const lat = Number(item.lat), lon = Number(item.lon);
            if (!isFinite(lat) || !isFinite(lon)) return;
            wire();
            const key = `${lat.toFixed(6)},${lon.toFixed(6)}`;
            let g = groups.get(key);
            if (!g) { g = { lat, lon, color, members: [], badge: null, legs: null }; groups.set(key, g); }
            g.members.push(marker);

            marker.on('click', () => { swallowMapClick(); if (g.members.length > 1) expand(g); });
            // also when opened from the list / arrow keys: show which pin it is among its neighbours
            marker.on('popupopen', () => { if (g.members.length > 1) expand(g); });
        },

        // Call once after all specimens were added: put a count badge on every spot with several.
        finish() {
            groups.forEach(g => {
                const n = g.members.length;
                if (n < 2 || g.badge) return;
                // Kept small so badges don't hide the route line: sized by the number of digits.
                const digits = String(n).length;
                const size   = digits === 1 ? 15 : digits === 2 ? 18 : 22;
                const font   = digits < 3 ? 9 : 8;
                g.badge = L.marker([g.lat, g.lon], {
                    keyboard: false,
                    title: `${n} specimens at this exact location — click to spread them out`,
                    icon: L.divIcon({
                        className: '', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
                        html: `<div style="width:${size}px;height:${size}px;box-sizing:border-box;border-radius:50%;background:${g.color};border:1.5px solid #fff;box-shadow:0 0 2px rgba(0,0,0,.5);opacity:.92;color:#fff;font:700 ${font}px/${size - 3}px sans-serif;text-align:center;cursor:pointer;">${n}</div>`,
                    }),
                }).addTo(map);
                g.badge.on('click', () => { swallowMapClick(); open === g ? collapse() : expand(g); });
            });
        },
    };
})();

// ── Route line ──────────────────────────────────────────────────────────────
// Joining every dated specimen in order draws a thick grey bundle (a thousand
// zig-zagging 1.5px lines). Specimens collected on the same day collapse to one
// vertex at their average position; itinerary points are kept as they are, and
// repeated vertices are dropped. Entries are [lat, lon, date, type].
const RouteLine = {
    collapse(points) {
        const out = [];
        for (let i = 0; i < points.length;) {
            const date = points[i][2], type = points[i][3];
            let j = i;
            if (date && type !== 'point') {
                while (j + 1 < points.length && points[j + 1][2] === date && points[j + 1][3] !== 'point') j++;
            }
            const grp = points.slice(i, j + 1).map(p => [Number(p[0]), Number(p[1])]);
            let pt = grp[0];
            if (grp.length > 1) {
                const lons = grp.map(p => p[1]);
                if (Math.max(...lons) - Math.min(...lons) <= 180) {      // don't average across the antimeridian
                    pt = [grp.reduce((a, p) => a + p[0], 0) / grp.length, grp.reduce((a, p) => a + p[1], 0) / grp.length];
                }
            }
            const prev = out[out.length - 1];
            if (!prev || Math.abs(prev[0] - pt[0]) > 1e-6 || Math.abs(prev[1] - pt[1]) > 1e-6) out.push(pt);
            i = j + 1;
        }
        return out;
    },
};
</script>
