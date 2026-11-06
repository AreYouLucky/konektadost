<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop resource foreign keys that still point at the legacy GADM tables.
 *
 * facilities, laboratories and telecom_sites reference old_municipalities / old_barangays,
 * whose IDs differ from the current municipalities / barangays (old 959 is Quezon City,
 * current 959 is Maribojoc). These constraints block `resources:relink-locations`.
 * Run order: this migration, then the relink command, then
 * 2026_11_06_000300_add_resource_location_foreign_keys.
 */
return new class extends Migration
{
    /** table => [column => legacy table] */
    private const LINKS = [
        'facilities' => ['municipality_id' => 'old_municipalities', 'barangay_id' => 'old_barangays'],
        'laboratories' => ['municipality_id' => 'old_municipalities', 'barangay_id' => 'old_barangays'],
        'telecom_sites' => ['municipality_id' => 'old_municipalities'],
    ];

    public function up(): void
    {
        foreach (self::LINKS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $key) {
                $legacy = $columns[$key['columns'][0] ?? ''] ?? null;
                if ($legacy !== null && count($key['columns']) === 1 && $key['foreign_table'] === $legacy) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key['name']));
                }
            }
        }
    }

    /** Restores the legacy constraints only where every stored value still exists in the legacy table. */
    public function down(): void
    {
        foreach (self::LINKS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = array_map(fn ($key) => $key['columns'][0] ?? null, Schema::getForeignKeys($table));
            foreach ($columns as $column => $legacy) {
                $orphans = Schema::hasTable($legacy) && DB::table($table)->whereNotNull($column)
                    ->whereNotIn($column, DB::table($legacy)->select('id'))->exists();
                if (! in_array($column, $existing, true) && Schema::hasTable($legacy) && ! $orphans) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, "{$table}_{$column}_foreign")
                        ->references('id')->on($legacy)->nullOnDelete());
                }
            }
        }
    }
};
