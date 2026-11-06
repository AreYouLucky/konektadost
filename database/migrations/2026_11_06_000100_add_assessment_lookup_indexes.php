<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lookup indexes for the imported domain tables.
 *
 * The dump ships without indexes on the columns every assessment joins on, so each
 * barangay or population lookup scanned the whole table (a cold Quezon City assessment
 * took ~44 s). Additive only: each index is skipped when its table, columns or name
 * is missing or already present, so this is safe on fresh installs and re-runs.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => [index name => columns] */
    private const INDEXES = [
        'barangays' => ['barangays_municipality_id_index' => ['municipality_id']],
        'municipalities' => [
            'municipalities_psg_code_index' => ['psg_code'],
            'municipalities_province_id_index' => ['province_id'],
        ],
        'population_figures' => [
            'population_figures_psg_code_year_index' => ['psg_code', 'year'],
            'population_figures_place_description_id_index' => ['place_description_id'],
        ],
        'place_descriptions' => ['place_descriptions_level_release_id_index' => ['level', 'release_id']],
        // Per-sector counts: with single-column indexes MariaDB full-scans facilities once per
        // barangay for the smaller sectors (~2.5 s per query for Quezon City).
        'facilities' => [
            'facilities_barangay_id_sector_index' => ['barangay_id', 'sector'],
            'facilities_municipality_id_sector_index' => ['municipality_id', 'sector'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = array_column(Schema::getIndexes($table), 'name');
            foreach ($indexes as $name => $columns) {
                if (in_array($name, $existing, true) || ! Schema::hasColumns($table, $columns)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = array_column(Schema::getIndexes($table), 'name');
            foreach (array_keys($indexes) as $name) {
                if (in_array($name, $existing, true)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }
};
