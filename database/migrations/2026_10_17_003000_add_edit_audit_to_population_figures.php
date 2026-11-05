<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for in-place corrections to published population figures.
     *
     * Figures are official statistics, so overwriting one destroys its
     * provenance. Instead the pre-edit value and the editor are retained, and
     * the row is flagged as corrected so benchmarks can exclude or annotate it.
     */
    public function up(): void
    {
        // population_figures holds imported data rather than being built by
        // migrations, so it is absent in a fresh test database.
        if (! Schema::hasTable('population_figures')) {
            return;
        }

        Schema::table('population_figures', function (Blueprint $table) {
            $table->unsignedBigInteger('original_population')->nullable()->after('population');
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();

            $table->foreign('edited_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('population_figures') || ! Schema::hasColumn('population_figures', 'edited_at')) {
            return;
        }

        Schema::table('population_figures', function (Blueprint $table) {
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['original_population', 'edited_by', 'edited_at']);
        });
    }
};
