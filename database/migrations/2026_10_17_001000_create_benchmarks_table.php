<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference rates an indicator is compared against.
     *
     * A raw count on its own is not a finding. "12 pharmacies" means nothing
     * until it is compared to a reference rate, and every reference rate needs
     * its own provenance: what it was computed from, how, and when.
     */
    public function up(): void
    {
        Schema::create('benchmarks', function (Blueprint $table) {
            $table->id();
            $table->string('indicator_key')->index();
            $table->string('scope')->default('national')->index();

            // GADM id of the area the benchmark applies to. Empty string for
            // national scope: MySQL treats NULLs as distinct in unique indexes,
            // which would allow duplicate national rows.
            $table->string('scope_ref')->default('');

            $table->decimal('value', 16, 4);
            $table->string('unit')->default('');
            $table->string('basis')->default('national_rate');
            $table->string('source')->default('');
            $table->string('source_url')->nullable();
            $table->string('vintage')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['indicator_key', 'scope', 'scope_ref', 'basis'],
                'benchmarks_indicator_scope_basis_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmarks');
    }
};
