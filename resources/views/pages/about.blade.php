@extends('layouts.app')

@section('title', 'About')

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">

<h1 class="h3 mb-1">About Expeditia</h1>
<p class="text-muted mb-4">Research expeditions on Wikidata, with itineraries and natural history specimens.</p>

<h5>What is Expeditia?</h5>
<p>Expeditia aggregates data about scientific and research expeditions from four open data sources — <a href="https://www.wikidata.org" target="_blank">Wikidata</a>, <a href="https://www.gbif.org" target="_blank">GBIF</a>, <a href="https://bionomia.net" target="_blank">Bionomia</a>, and <a href="https://georeference.it" target="_blank">georeference.it</a> — and presents them as interactive maps with chronological itineraries and natural history specimen records.</p>

<p>For each expedition you can explore the route on a map, browse the participants, and see the specimens collected — with links back to the original records in GBIF.</p>

<p class="small text-muted">A previous version of Expeditia is still available at <a href="https://www.expeditia.info/old" target="_blank">expeditia.info/old</a> for reference.</p>

<h5 class="mt-4">Where does the data come from?</h5>

<div class="card mb-2">
    <div class="card-body py-3">
        <h6 class="mb-1"><a href="https://www.wikidata.org" target="_blank">Wikidata</a></h6>
        <p class="small text-muted mb-0">Provides expedition metadata (name, dates, description), the itinerary (start, via and end points with coordinates), and the list of participants. Expeditions are identified as instances of <a href="https://www.wikidata.org/wiki/Q366301" target="_blank">Q366301 (expedition)</a>.</p>
    </div>
</div>

<div class="card mb-2">
    <div class="card-body py-3">
        <h6 class="mb-1"><a href="https://www.gbif.org" target="_blank">GBIF</a></h6>
        <p class="small text-muted mb-0">Provides natural history specimen occurrence records. Specimens are linked to expeditions either via the Wikidata property <a href="https://www.wikidata.org/wiki/Property:P11146" target="_blank">P11146</a> (GBIF occurrence ID), or by matching the expedition's Wikidata QID against GBIF's <code>eventID</code> and <code>parentEventID</code> fields.</p>
    </div>
</div>

<div class="card mb-2">
    <div class="card-body py-3">
        <h6 class="mb-1"><a href="https://bionomia.net" target="_blank">Bionomia</a></h6>
        <p class="small text-muted mb-0">Acts as a broker between collectors (identified by Wikidata QIDs / ORCID) and GBIF specimen records. Volunteers use Bionomia to attribute specimens to the people who collected or identified them. Expeditia queries Bionomia to discover which GBIF specimens are attributed to each expedition participant, then retrieves and enriches those records directly from GBIF, filtering by the expedition date range.</p>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body py-3">
        <h6 class="mb-1"><a href="https://georeference.it" target="_blank">georeference.it</a></h6>
        <p class="small text-muted mb-0">A crowdsourcing platform dedicated to georeferencing GBIF occurrence records — assigning or correcting coordinates for specimens whose original locality data is missing, vague, or wrong. Where georeference.it has a validated or suggested coordinate for a specimen, Expeditia prefers it over GBIF's own coordinate, since it's often more accurate.</p>
    </div>
</div>

<h5>How to add an expedition</h5>
<p>An expedition appears in Expeditia when it meets the following conditions:</p>
<ul>
    <li>It exists in Wikidata as an instance of <a href="https://www.wikidata.org/wiki/Q366301" target="_blank">Q366301 (expedition)</a></li>
    <li>It has at least a label and ideally start/end dates and participants</li>
</ul>
<p>Once the Wikidata entry exists, you can add it directly from the <a href="{{ route('expeditions.index') }}">Expeditions</a> page using the <strong>+ Add QID</strong> button and entering the Wikidata QID (e.g. <code>Q1564366</code>).</p>

<h5 class="mt-4">How to get specimens to appear</h5>
<p>For specimen records from GBIF to be associated with an expedition, one of the following must be true:</p>
<ul>
    <li>The Wikidata item for the expedition has the property <a href="https://www.wikidata.org/wiki/Property:P11146" target="_blank">P11146</a> (GBIF occurrence ID) linking directly to specimen records</li>
    <li>GBIF occurrence records have the expedition's Wikidata QID in their <code>eventID</code> or <code>parentEventID</code> field</li>
    <li>A participant has an Wikidata QID / ORCID and their specimens are attributed in <a href="https://bionomia.net" target="_blank">Bionomia</a>, with event dates that fall within the expedition's date range</li>
</ul>

<h5 class="mt-4">How often is data updated?</h5>
<p>Expeditia runs an automatic sync via a scheduled task. You can also trigger a manual sync for any expedition or person using the <strong>Sync</strong> button on their respective pages.</p>

<h5 class="mt-4">WikiProject</h5>
<p>Expeditia is developed in the context of the <a href="https://www.wikidata.org/wiki/Wikidata:WikiProject_Research_expeditions" target="_blank">Wikidata Research Expeditions WikiProject</a>, which aims to document scientific and research expeditions in Wikidata and make their data more accessible.</p>

<h5 class="mt-4">Licence &amp; citation</h5>
<div class="card bg-light border-0">
    <div class="card-body py-3">
        <p class="small mb-2">Data is made available under the <a href="http://opendatacommons.org/licenses/odbl/1.0/" target="_blank">Open Database License (ODbL)</a>. Individual contents are licensed under the <a href="http://opendatacommons.org/licenses/dbcl/1.0/" target="_blank">Database Contents License (DbCL)</a>.</p>
        <p class="small mb-0 font-monospace">Santos, Joaquim (<a href="https://orcid.org/0000-0002-2160-4968" target="_blank">0000-0002-2160-4968</a>) (2023–2026) <em>Expeditia: Research Expeditions on Wikidata with itineraries.</em> <a href="https://www.expeditia.info" target="_blank">https://www.expeditia.info</a></p>
    </div>
</div>

<div class="mt-4 text-center">
    <a href="{{ route('about.developers') }}" class="btn btn-outline-secondary btn-sm">For developers — API &amp; data access →</a>
</div>

</div>
</div>
@endsection