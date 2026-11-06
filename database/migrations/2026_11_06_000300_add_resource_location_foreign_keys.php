<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Point resource location links at the current geography tables.
 *
 * Run after `php artisan resources:relink-locations --apply`. A constraint is added only when
 * no stored value is orphaned, so this never fails half-way on unrepaired data.
 */
return new class extends Migration
{
    /** table => [column => current table] */
    private const LINKS = [
        'facilities' => ['municipality_id' => 'municipalities', 'barangay_id' => 'barangays'],
        'laboratories' => ['municipality_id' => 'municipalities', 'barangay_id' => 'barangays'],
        'telecom_sites' => ['municipality_id' => 'municipalities', 'barangay_id' => 'barangays'],
    ];

    public function up(): void
    {
        foreach (self::LINKS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = array_map(fn ($key) => $key['columns'][0] ?? null, Schema::getForeignKeys($table));
            foreach ($columns as $column => $parent) {
                if (in_array($column, $existing, true) || ! Schema::hasColumn($table, $column) || ! Schema::hasTable($parent)) {
                    continue;
                }
                if (DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table($parent)->select('id'))->exists()) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, "{$table}_{$column}_current_foreign")
                    ->references('id')->on($parent)->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (self::LINKS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $key) {
                if (in_array($key['name'], array_map(fn ($column) => "{$table}_{$column}_current_foreign", array_keys($columns)), true)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key['name']));
                }
            }
        }
    }
};
