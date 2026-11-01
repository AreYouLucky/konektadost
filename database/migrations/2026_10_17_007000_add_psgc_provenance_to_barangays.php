<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the provenance needed alongside barangays.psgc_code.
 *
 * The column itself already exists but was NULL for all 41,929 rows, so this
 * migration only indexes it and records how each code was resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // barangays is an imported table with no migration of its own, so guard
        // on its presence rather than assume it.
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'psgc_code')) {
            return;
        }

        Schema::table('barangays', function (Blueprint $table) {
            if (! Schema::hasColumn('barangays', 'psgc_match_status')) {
                $table->string('psgc_match_status')->nullable();
            }

            if (! Schema::hasColumn('barangays', 'psgc_release')) {
                $table->string('psgc_release')->nullable();
            }
        });

        // psgc_code is now a real join key, so it needs an index.
        Schema::table('barangays', function (Blueprint $table) {
            $indexed = collect(Schema::getIndexes('barangays'))
                ->contains(fn (array $index) => $index['name'] === 'barangays_psgc_code_index');

            if (! $indexed) {
                $table->index('psgc_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('barangays') || ! Schema::hasColumn('barangays', 'psgc_match_status')) {
            return;
        }

        Schema::table('barangays', function (Blueprint $table) {
            $table->dropColumn(['psgc_match_status', 'psgc_release']);
        });
    }
};
