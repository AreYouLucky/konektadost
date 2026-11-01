-- Cell sites and telecommunication base stations.
--
-- This table is created outside the Laravel migration history on purpose: the
-- dataset was delivered as a workbook, not as a change to the application's
-- schema, and the migration log no longer describes how this data arrived.
-- Run this file directly to recreate the table.
--
--   mysql -u root konektadost < database/schema/telecom_sites.sql
--
-- `barangay_id` is the primary relationship to the GADM based `barangays`
-- table. GADM predates the 2021 PSA reorganisation of Taguig, so ten of the
-- barangays in this dataset have no `barangays` row and their `barangay_id`
-- is left NULL. `barangay_psgc10` and `barangay_psgc_code` carry the PSA
-- identity for every row and join to `psgc_units`, so an unmatched site is
-- still a located site.

CREATE TABLE IF NOT EXISTS `telecom_sites` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(64) NOT NULL DEFAULT 'taguig_cell_sites',
    `source_record_id` VARCHAR(32) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `district` VARCHAR(32) NULL,
    `barangay_name` VARCHAR(128) NOT NULL,
    `barangay_psgc10` CHAR(10) NULL,
    `barangay_psgc_code` CHAR(9) NULL,
    `barangay_id` BIGINT UNSIGNED NULL,
    `municipality_id` BIGINT UNSIGNED NULL,
    `tower_company` VARCHAR(64) NULL,
    `operator` VARCHAR(64) NULL,
    `operator_raw` VARCHAR(128) NULL,
    `is_colocated` TINYINT(1) NOT NULL DEFAULT 0,
    `mcc` VARCHAR(3) NULL,
    `mnc` VARCHAR(3) NULL,
    `lac` VARCHAR(16) NULL,
    `cell_id` VARCHAR(32) NULL,
    `latitude` DECIMAL(10,7) NOT NULL,
    `longitude` DECIMAL(10,7) NOT NULL,
    `technologies` JSON NULL,
    `bands` JSON NULL,
    `structure_type` VARCHAR(128) NULL,
    `coverage_area` TEXT NULL,
    `source_checked` DATE NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `telecom_sites_source_record_unique` (`source`, `source_record_id`),
    KEY `telecom_sites_barangay_psgc10_index` (`barangay_psgc10`),
    KEY `telecom_sites_barangay_id_index` (`barangay_id`),
    KEY `telecom_sites_municipality_id_index` (`municipality_id`),
    KEY `telecom_sites_operator_index` (`operator`),
    CONSTRAINT `telecom_sites_barangay_id_foreign`
        FOREIGN KEY (`barangay_id`) REFERENCES `barangays` (`id`) ON DELETE SET NULL,
    CONSTRAINT `telecom_sites_municipality_id_foreign`
        FOREIGN KEY (`municipality_id`) REFERENCES `municipalities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
