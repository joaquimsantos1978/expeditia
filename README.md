# Expeditia

Research expeditions on Wikidata, with itineraries and natural history specimens.

**Live site:** [expeditia.info](https://www.expeditia.info)

## What is Expeditia?

Expeditia aggregates data about scientific and research expeditions from four open data sources — [Wikidata](https://www.wikidata.org), [GBIF](https://www.gbif.org), [Bionomia](https://bionomia.net), and [georeference.it](https://georeference.it) — and presents them as interactive maps with chronological itineraries and natural history specimen records.

For each expedition you can explore the route on a map, browse the participants, and see the specimens collected, with links back to the original records in GBIF.

- **Wikidata** provides expedition metadata (name, dates, description), the itinerary (start, via and end points with coordinates), and the list of participants. Expeditions are identified as instances of [Q366301 (expedition)](https://www.wikidata.org/wiki/Q366301).
- **GBIF** provides natural history specimen occurrence records. Specimens are linked to expeditions either via the Wikidata property [P11146](https://www.wikidata.org/wiki/Property:P11146) (GBIF occurrence ID), or by matching the expedition's Wikidata QID against GBIF's `eventID`/`parentEventID` fields, or by date range (see below).
- **Bionomia** acts as a broker between collectors (identified by Wikidata QID / ORCID) and GBIF specimen records. Expeditia queries Bionomia to discover which GBIF specimens are attributed to each expedition participant, then retrieves and enriches those records directly from GBIF, filtering by the expedition's date range.
- **georeference.it** is a crowdsourcing platform dedicated to georeferencing GBIF occurrence records. Where it has a validated or suggested coordinate for a specimen, Expeditia prefers it over GBIF's own coordinate, since it's often more accurate.

Full details are on the [About](https://www.expeditia.info/about) page.

## Architecture notes

A few decisions here are shaped by the fact that Expeditia runs on ordinary shared hosting, with no SSH access and no queue workers:

- **No queues.** Sync work is broken into small, time-budgeted steps chained together via HTTP redirects (`SyncController`), coordinated through a generic key/value `sync_state` table instead of jobs. A single sync run for a large expedition happens across many chained requests rather than one long-running process.
- **No cron on the server itself** in the traditional sense — `/sync/cron` is hit periodically (e.g. by an external scheduler such as cron-job.org) to drive routine syncs and re-check "complete" data periodically, since publishers correct their records over time.
- **Manual deploys.** Schema changes are shipped as both a Laravel migration (for local dev / anyone with SSH) and equivalent raw SQL (for the production deploy, which is file upload + a manual `ALTER TABLE`/`INSERT INTO migrations` via phpMyAdmin or similar).
- **Provenance-aware coordinates.** Each specimen tracks `coord_source` (`gbif` / `bionomia` / `georeference`) and `coord_status`, so a higher-priority source's correction is never silently clobbered by a lower-priority one on a later sync.

## Getting started

Requirements: PHP 8.2+, Composer, MySQL (the app uses MySQL-specific FULLTEXT search indexes, so SQLite won't work), Node.js.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Set your database credentials in `.env`, create the database, then build the schema in two steps:

```bash
# 1. Base tables — these predate the migration history, so `migrate` alone
#    won't create them. See database/sql/schema.sql for details.
mysql -u root -p expeditia < database/sql/schema.sql

# 2. Everything added since, tracked as normal Eloquent migrations
php artisan migrate
```

Build frontend assets and start the dev server:

```bash
npm run build   # or `npm run dev` while working on styles/JS
php artisan serve
```

### Populating data

The app starts empty. Add an expedition from the **Expeditions** page (**+ Add QID** button) with a Wikidata QID that's an instance of [Q366301](https://www.wikidata.org/wiki/Q366301) (e.g. `Q1564366`), or trigger a full sync via:

```
GET /sync/cron
```

If `SYNC_TOKEN` is set in `.env`, append `?token=...` to every `/sync/*` request; leave it unset for open access during local development.

## Testing

```bash
php artisan test
```

## Licence

Code is available under the [MIT licence](LICENSE). The underlying data is aggregated from Wikidata, GBIF, Bionomia and georeference.it under their respective licences; Expeditia's own database is made available under the [Open Database License (ODbL)](http://opendatacommons.org/licenses/odbl/1.0/), with individual contents under the [Database Contents License (DbCL)](http://opendatacommons.org/licenses/dbcl/1.0/).

## Citation

> Santos, Joaquim ([0000-0002-2160-4968](https://orcid.org/0000-0002-2160-4968)) (2023–2026) *Expeditia: Research Expeditions on Wikidata with itineraries.* <https://www.expeditia.info>

## WikiProject

Expeditia is developed in the context of the [Wikidata Research Expeditions WikiProject](https://www.wikidata.org/wiki/Wikidata:WikiProject_Research_expeditions), which aims to document scientific and research expeditions in Wikidata and make their data more accessible.
