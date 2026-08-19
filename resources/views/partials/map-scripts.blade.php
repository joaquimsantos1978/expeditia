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
</script>
