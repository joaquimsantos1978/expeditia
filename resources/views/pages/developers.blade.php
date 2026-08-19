@extends('layouts.app')

@section('title', 'For Developers')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-9">

<h1 class="h3 mb-1">For Developers</h1>
<p class="text-muted mb-4">Data access via API, JSON and CSV exports.</p>

<div class="alert alert-light border small">
    Data from Expeditia is made available under the <a href="http://opendatacommons.org/licenses/odbl/1.0/" target="_blank">Open Database License (ODbL)</a>. Individual contents are licensed under the <a href="http://opendatacommons.org/licenses/dbcl/1.0/" target="_blank">Database Contents License (DbCL)</a>. Original data sources are <a href="https://www.wikidata.org" target="_blank">Wikidata</a> (CC0), <a href="https://www.gbif.org" target="_blank">GBIF</a> (various), and <a href="https://bionomia.net" target="_blank">Bionomia</a> (CC0).
</div>

<p class="small text-muted mb-1">Note: responses are plain JSON, not JSON-LD. Semantic enrichment is planned for a future version.</p>

{{-- ── Exports ─────────────────────────────────────────────────── --}}
<h5 class="mt-4">CSV &amp; JSON exports</h5>
<p>Every expedition and person page has CSV and JSON download buttons. These export the full dataset without pagination.</p>

<table class="table table-sm table-bordered small">
    <thead class="table-light">
        <tr><th>Endpoint</th><th>Description</th></tr>
    </thead>
    <tbody>
        <tr><td><code>/expedition/{qid}/export/csv</code></td><td>All map items for an expedition as CSV</td></tr>
        <tr><td><code>/expedition/{qid}/export/json</code></td><td>All map items for an expedition as JSON</td></tr>
        <tr><td><code>/person/{qid}/export/csv</code></td><td>All specimens for a person as CSV</td></tr>
        <tr><td><code>/person/{qid}/export/json</code></td><td>All specimens for a person as JSON</td></tr>
    </tbody>
</table>

{{-- ── API ──────────────────────────────────────────────────────── --}}
<h5 class="mt-4">REST API</h5>
<p>All API endpoints return JSON and do not require authentication.</p>

<table class="table table-sm table-bordered small mb-4">
    <thead class="table-light">
        <tr><th>Endpoint</th><th>Description</th></tr>
    </thead>
    <tbody>
        <tr><td><code>GET /api/expeditions</code></td><td>Paginated list of expeditions</td></tr>
        <tr><td><code>GET /api/expedition/{qid}</code></td><td>Expedition metadata</td></tr>
        <tr><td><code>GET /api/expedition/{qid}/items</code></td><td>All map items (points + specimens)</td></tr>
        <tr><td><code>GET /api/person/{qid}</code></td><td>Person metadata</td></tr>
        <tr><td><code>GET /api/person/{qid}/specimens</code></td><td>All specimens for a person</td></tr>
    </tbody>
</table>

{{-- ── Example: expedition items ───────────────────────────────── --}}
<h6><code>GET /api/expedition/Q1564366/items</code></h6>
<p class="small text-muted">Returns the full chronological list of itinerary points and specimens for the Second voyage of HMS Beagle.</p>
<pre class="bg-dark text-light p-3 rounded small" style="overflow-x:auto;">{
  "expedition": {
    "wikidata_qid": "Q1564366",
    "label": "Second voyage of HMS Beagle",
    "description": "exploration associated with the theory of evolution (1831-1836)",
    "start_time": "1831-12-27",
    "end_time": "1836-10-02",
    "wikidata_url": "https://www.wikidata.org/wiki/Q1564366"
  },
  "items": [
    {
      "type": "point",
      "date_label": "1831-12-27",
      "name": "Plymouth",
      "lat": "50.3713889",
      "lon": "-4.1422222",
      "gbif_id": null,
      "scientific_name": null,
      ...
    },
    {
      "type": "specimen",
      "date_label": "1832-01-01",
      "name": "Suaeda",
      "lat": null,
      "lon": null,
      "gbif_id": "912674682",
      "scientific_name": "Suaeda",
      "family": "Amaranthaceae",
      "kingdom": "Plantae",
      "recorded_by": "Darwin, C.",
      "institution_code": "K",
      "collection_code": "Herbarium",
      "catalog_number": "K000899769",
      "basis_of_record": "PRESERVED_SPECIMEN",
      "type_status": "unknown type material",
      "locality": "Bahía Blanca; Coast of Patagonia",
      "country": "Argentina",
      "country_code": "AR",
      "event_date": "1832-01-01",
      "gbif_url": "https://www.gbif.org/occurrence/912674682"
    }
  ]
}</pre>

{{-- ── Item fields ─────────────────────────────────────────────── --}}
<h6 class="mt-4">Item fields</h6>
<table class="table table-sm table-bordered small">
    <thead class="table-light">
        <tr><th>Field</th><th>Type</th><th>Description</th></tr>
    </thead>
    <tbody>
        <tr><td><code>type</code></td><td>string</td><td><code>point</code> or <code>specimen</code></td></tr>
        <tr><td><code>date_label</code></td><td>string</td><td>Date or date range as recorded</td></tr>
        <tr><td><code>name</code></td><td>string</td><td>Place name (points) or scientific name (specimens)</td></tr>
        <tr><td><code>lat</code> / <code>lon</code></td><td>decimal / null</td><td>Coordinates — may be null</td></tr>
        <tr><td><code>gbif_id</code></td><td>integer / null</td><td>GBIF occurrence ID (specimens only)</td></tr>
        <tr><td><code>scientific_name</code></td><td>string / null</td><td>Full scientific name (specimens only)</td></tr>
        <tr><td><code>family</code></td><td>string / null</td><td>Taxonomic family</td></tr>
        <tr><td><code>kingdom</code></td><td>string / null</td><td>Taxonomic kingdom</td></tr>
        <tr><td><code>recorded_by</code></td><td>string / null</td><td>Collector name as recorded in GBIF</td></tr>
        <tr><td><code>identified_by</code></td><td>string / null</td><td>Identifier name</td></tr>
        <tr><td><code>institution_code</code></td><td>string / null</td><td>Holding institution</td></tr>
        <tr><td><code>collection_code</code></td><td>string / null</td><td>Collection within institution</td></tr>
        <tr><td><code>catalog_number</code></td><td>string / null</td><td>Catalogue number</td></tr>
        <tr><td><code>basis_of_record</code></td><td>string / null</td><td>e.g. <code>PRESERVED_SPECIMEN</code></td></tr>
        <tr><td><code>type_status</code></td><td>string / null</td><td>Type status if applicable</td></tr>
        <tr><td><code>locality</code></td><td>string / null</td><td>Locality as interpreted</td></tr>
        <tr><td><code>verbatim_locality</code></td><td>string / null</td><td>Locality as originally recorded</td></tr>
        <tr><td><code>country</code></td><td>string / null</td><td>Country name</td></tr>
        <tr><td><code>country_code</code></td><td>string / null</td><td>ISO 3166-1 alpha-2</td></tr>
        <tr><td><code>event_date</code></td><td>string / null</td><td>Collection date</td></tr>
        <tr><td><code>gbif_url</code></td><td>string / null</td><td>Direct link to GBIF occurrence</td></tr>
    </tbody>
</table>

<div class="mt-4 text-center">
    <a href="{{ route('about') }}" class="btn btn-outline-secondary btn-sm">← Back to About</a>
</div>

</div>
</div>
@endsection
