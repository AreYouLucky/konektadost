<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Land area in square kilometres, needed for density and per-capita
     * indicators. Populated by `php artisan geo:compute-area`.
     *
     * @var list<string>
     */
    private array $tables = ['provinces', 'municipalities', 'barangays'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            // These tables hold imported GADM/PSA data rather than being built
            // by migrations, so they are absent in a fresh test database or an
            // environment where the import has not run yet.
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'area_km2')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('area_km2', 12, 4)->nullable()->after('center_lng');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'area_km2')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('area_km2');
            });
        }
    }
};
