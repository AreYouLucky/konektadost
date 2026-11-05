<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the PSA PSGC attributes to the geography tables.
 *
 * These columns are a projection of psgc_units and nothing more. Every value
 * here is reproducible by re-running `geo:sync-psgc`, so unlike psgc_units this
 * table does not need to be the system of record; if the columns are lost the
 * sync restores them.
 *
 * psgc_code (nine digit) and psgc10 (ten digit) are both kept. The geography
 * tables already carry the nine digit code and it is used by existing joins,
 * while the ten digit code is what the current PSA publication identifies a
 * unit by.
 *
 * Income class and city class are absent from barangays on purpose: PSA only
 * publishes them for local government units, so a barangay has no value to
 * hold and none is invented.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ATTRIBUTES = [
        'provinces' => ['psgc_code', 'psgc10', 'old_name', 'city_class', 'income_class', 'urban_rural', 'population_2024', 'status'],
        'municipalities' => ['psgc10', 'old_name', 'city_class', 'income_class', 'urban_rural', 'population_2024', 'status'],
        'barangays' => ['psgc10', 'old_name', 'urban_rural', 'population_2024', 'status'],
    ];

    public function up(): void
    {
        foreach (self::ATTRIBUTES as $table => $columns) {
            // Imported tables with no migration of their own, so guard rather
            // than assume presence.
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $table) {
                $added = [];

                foreach ($columns as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    $this->addColumn($blueprint, $column);
                    $added[] = $column;
                }

                foreach ($added as $column) {
                    if (in_array($column, ['psgc10', 'population_2024'], true)) {
                        $blueprint->index($column);
                    }
                }
            });
        }

        // A barangay belongs to a municipality. The existing link is through
        // GADM ids, which is a geometry relation rather than an administrative
        // one; this adds the administrative link alongside it rather than
        // replacing it, so nothing that depends on the old column breaks.
        if (Schema::hasTable('barangays') && ! Schema::hasColumn('barangays', 'municipality_id')) {
            Schema::table('barangays', function (Blueprint $table) {
                $table->unsignedBigInteger('municipality_id')->nullable()->after('municipality_gadm_id');

                $indexed = collect(Schema::getIndexes('barangays'))
                    ->contains(fn (array $index) => $index['name'] === 'barangays_municipality_id_foreign');

                if (! $indexed) {
                    $table->index('municipality_id');
                }
            });
        }
    }

    private function addColumn(Blueprint $table, string $column): void
    {
        match ($column) {
            'population_2024' => $table->unsignedBigInteger($column)->nullable(),
            'income_class', 'urban_rural', 'status' => $table->string($column, 16)->nullable(),
            'psgc_code' => $table->string($column, 16)->nullable(),
            'psgc10' => $table->string($column, 10)->nullable(),
            default => $table->string($column)->nullable(),
        };
    }

    public function down(): void
    {
        foreach (self::ATTRIBUTES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                fn (string $column) => Schema::hasColumn($table, $column)
            ));

            if ($present !== []) {
                Schema::table($table, function (Blueprint $blueprint) use ($present) {
                    $blueprint->dropColumn($present);
                });
            }
        }

        if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'municipality_id')) {
            Schema::table('barangays', function (Blueprint $table) {
                $table->dropColumn('municipality_id');
            });
        }
    }
};
