-- Core Expeditia schema.
--
-- These tables predate the migration history in database/migrations and were
-- never captured as Eloquent migrations, so `php artisan migrate` alone will
-- NOT create them. Run this file once against a fresh database (after Laravel's
-- own default migrations have run for `users`, `cache`, `jobs`, etc.), then run
-- `php artisan migrate` to layer on the newer ALTER-table migrations in
-- database/migrations (coord_source, coord_uncertainty_m, location fields, ...).
--
-- Generated from the live schema with `SHOW CREATE TABLE`; AUTO_INCREMENT
-- start values below are cosmetic and safe to ignore.

CREATE TABLE `expeditions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wikidata_qid` varchar(24) NOT NULL,
  `label` varchar(512) DEFAULT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` text DEFAULT NULL,
  `start_time` varchar(32) DEFAULT NULL,
  `end_time` varchar(32) DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `retired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `commanded_by` text DEFAULT NULL,
  `vessel` text DEFAULT NULL,
  `organizer` text DEFAULT NULL,
  `goal` text DEFAULT NULL,
  `field_of_work` text DEFAULT NULL,
  `country` text DEFAULT NULL,
  `named_after` text DEFAULT NULL,
  `external_url` varchar(500) DEFAULT NULL,
  `end_cause` text DEFAULT NULL,
  `follows_qid` text DEFAULT NULL,
  `followed_by_qid` text DEFAULT NULL,
  `num_participants` int(10) unsigned DEFAULT NULL,
  `num_deaths` int(10) unsigned DEFAULT NULL,
  `search_text` mediumtext DEFAULT NULL,
  `wikipedia_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `expeditions_wikidata_qid_unique` (`wikidata_qid`),
  FULLTEXT KEY `idx_expeditions_search` (`search_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `people` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `wikidata_qid` varchar(24) NOT NULL,
  `name` varchar(512) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `aliases` text DEFAULT NULL,
  `image` text DEFAULT NULL,
  `date_birth` varchar(32) DEFAULT NULL,
  `place_birth` varchar(512) DEFAULT NULL,
  `date_death` varchar(32) DEFAULT NULL,
  `place_death` varchar(512) DEFAULT NULL,
  `occupation` text DEFAULT NULL,
  `orcid` varchar(64) DEFAULT NULL,
  `bionomia_id` varchar(64) DEFAULT NULL,
  `bionomia_synced_at` timestamp NULL DEFAULT NULL,
  `bionomia_etag` varchar(64) DEFAULT NULL,
  `synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `search_text` mediumtext DEFAULT NULL,
  `wikipedia_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `people_wikidata_qid_unique` (`wikidata_qid`),
  FULLTEXT KEY `idx_people_search` (`search_text`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `specimens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `person_id` int(10) unsigned DEFAULT NULL,
  `gbif_id` varchar(20) DEFAULT NULL,
  `dataset_key` varchar(64) DEFAULT NULL,
  `occurrence_id` varchar(255) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `basis_of_record` text DEFAULT NULL,
  `license` text DEFAULT NULL,
  `scientific_name` varchar(512) DEFAULT NULL,
  `family` text DEFAULT NULL,
  `kingdom` text DEFAULT NULL,
  `type_status` text DEFAULT NULL,
  `recorded_by` varchar(512) DEFAULT NULL,
  `recorded_by_id` text DEFAULT NULL,
  `identified_by` varchar(512) DEFAULT NULL,
  `identified_by_id` text DEFAULT NULL,
  `institution_code` text DEFAULT NULL,
  `collection_code` text DEFAULT NULL,
  `catalog_number` text DEFAULT NULL,
  `date_identified` text DEFAULT NULL,
  `event_date` varchar(64) DEFAULT NULL,
  `year` text DEFAULT NULL,
  `country` text DEFAULT NULL,
  `country_code` text DEFAULT NULL,
  `locality` text DEFAULT NULL,
  `verbatim_locality` text DEFAULT NULL,
  `higher_geography` varchar(255) DEFAULT NULL,
  `state_province` varchar(255) DEFAULT NULL,
  `county` varchar(255) DEFAULT NULL,
  `municipality` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `coord_source` varchar(20) DEFAULT NULL,
  `coord_status` varchar(20) DEFAULT NULL,
  `coord_uncertainty_m` int(10) unsigned DEFAULT NULL,
  `gbif_synced_at` timestamp NULL DEFAULT NULL,
  `image` text DEFAULT NULL,
  `gbif_url` varchar(255) DEFAULT NULL,
  `bionomia_created_by` varchar(512) DEFAULT NULL,
  `bionomia_created_by_uri` varchar(255) DEFAULT NULL,
  `bionomia_created` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `specimens_gbif_id_unique` (`gbif_id`),
  KEY `specimens_person_id_index` (`person_id`),
  KEY `specimens_country_code_index` (`country_code`(768)),
  KEY `specimens_event_date_index` (`event_date`),
  CONSTRAINT `fk_specimens_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `expedition_id` int(10) unsigned NOT NULL,
  `wikidata_qid` varchar(24) DEFAULT NULL,
  `type` enum('start','via','end') NOT NULL DEFAULT 'via',
  `name` varchar(512) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` text DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `start_time` varchar(32) DEFAULT NULL,
  `end_time` varchar(32) DEFAULT NULL,
  `point_in_time` varchar(32) DEFAULT NULL,
  `order` smallint(5) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `points_expedition_id_index` (`expedition_id`),
  KEY `points_wikidata_qid_index` (`wikidata_qid`),
  CONSTRAINT `fk_points_expedition` FOREIGN KEY (`expedition_id`) REFERENCES `expeditions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `map_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `expedition_id` int(10) unsigned NOT NULL,
  `order` smallint(5) unsigned NOT NULL,
  `type` enum('point','specimen') NOT NULL,
  `source_id` int(10) unsigned NOT NULL,
  `date_label` varchar(32) DEFAULT NULL,
  `name` varchar(512) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `image` text DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `map_items_expedition_order_index` (`expedition_id`,`order`),
  CONSTRAINT `fk_map_items_expedition` FOREIGN KEY (`expedition_id`) REFERENCES `expeditions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expedition_occurrences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `expedition_id` int(10) unsigned NOT NULL,
  `specimen_id` int(10) unsigned NOT NULL,
  `person_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exp_occ_unique` (`expedition_id`,`specimen_id`),
  KEY `exp_occ_expedition_index` (`expedition_id`),
  KEY `exp_occ_specimen_index` (`specimen_id`),
  KEY `fk_expocc_person` (`person_id`),
  CONSTRAINT `fk_expocc_expedition` FOREIGN KEY (`expedition_id`) REFERENCES `expeditions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expocc_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expocc_specimen` FOREIGN KEY (`specimen_id`) REFERENCES `specimens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expedition_people` (
  `expedition_id` int(10) unsigned NOT NULL,
  `person_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`expedition_id`,`person_id`),
  KEY `fk_ep_person` (`person_id`),
  CONSTRAINT `fk_ep_expedition` FOREIGN KEY (`expedition_id`) REFERENCES `expeditions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ep_person` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sync_state` (
  `key` varchar(64) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
