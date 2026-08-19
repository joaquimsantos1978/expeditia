/* ── Map sizing ─────────────────────────────────────────────────────────────── */
#map { height: 500px; width: 100%; border-radius: 4px; }

/* ── Items list ──────────────────────────────────────────────────────────────── */
#itemsList {
    max-height: 500px;
    overflow-y: auto;
    overflow-x: hidden;
}

/* ── Map popup ───────────────────────────────────────────────────────────────── */
.leaflet-popup-content { font-size: 14px; line-height: 1.5; }
.leaflet-popup-content img {
    width: 100%; object-fit: contain; background: #f8f9fa;
    border-radius: 4px; margin-bottom: 8px;
}
.leaflet-popup-content strong { font-size: 15px; display: block; margin-bottom: 4px; }
.leaflet-popup-content-wrapper { border-radius: 8px; box-shadow: 0 3px 12px rgba(0,0,0,0.2); }

/* ── Row states ──────────────────────────────────────────────────────────────── */
.map-item-row:hover { background: #f0f4ff; }
.map-item-row.active { background: #e8f0fe; border-left: 3px solid #0d6efd; color: #000 !important; }
.map-item-row.active strong,
.map-item-row.active p,
.map-item-row.active span { color: #000 !important; }
.map-item-row.no-coords { background: #fafafa; }
.map-item-row.no-coords:hover { background: #f0f0f0; }
.timeline-dot { width:12px; height:12px; border-radius:50%; background:#0d6efd; display:inline-block; margin-right:8px; }
.timeline-dot.specimen { background: #198754; }

/* ── Institution badge ───────────────────────────────────────────────────────── */
.institution-badge {
    font-size: 10px;
    background: #f1f3f5;
    color: #495057;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    padding: 1px 5px;
    white-space: nowrap;
}

/* ── Expedition header image ─────────────────────────────────────────────────── */
.expedition-header-image img {
    max-height: 320px;
    object-fit: cover;
    width: 100%;
}

/* ── Specimen modal ──────────────────────────────────────────────────────────── */
#specimenModal td { word-break: break-word; white-space: normal; max-width: 300px; }
#specimenModal th { white-space: nowrap; vertical-align: top; }

/* ── Mobile overrides ────────────────────────────────────────────────────────── */
@media (max-width: 991px) {
    #map { height: 350px; }
    #itemsList { max-height: 400px; }
    .expedition-header-image { margin-top: 1rem; }
    .expedition-header-image img { max-height: 200px; }
    .leaflet-popup-content { font-size: 13px; }
    .leaflet-popup-content img { max-height: 120px; }
}
