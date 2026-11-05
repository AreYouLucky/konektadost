<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A verbatim mirror of the PSA PSGC publication datafile.
 *
 * This is the source of truth for geographic identity and attributes. The
 * provinces/municipalities/barangays tables are GADM geometry and are rebuilt
 * by a dump that drops and recreates them, so PSA values are kept here, in a
 * table this project's own import command owns, and joined one way.
 *
 * Values are stored exactly as PSA published them. Income class keeps its
 * asterisk markers ("2nd*" means the LGU keeps a downgraded class under
 * RA 11964 section 10) and its "-" placeholder, because normalising those
 * would destroy the distinction PSA is drawing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->constrained('psgc_releases')->cascadeOnDelete();

            // The canonical identifier in this release.
            $table->string('psgc10', 10);

            $table->string('name');
            // The 9-digit correspondence code, which is what the existing
            // geography tables already store as psgc_code.
            $table->string('correspondence_code', 9)->nullable();

            // Reg, Prov, City, Mun, SubMun or Bgy. Rows without one of these
            // are not units and are rejected at import.
            $table->string('level', 8);

            $table->string('old_name')->nullable();
            $table->string('city_class', 8)->nullable();
            $table->string('income_class', 8)->nullable();
            $table->char('urban_rural', 1)->nullable();
            $table->unsignedBigInteger('population_2024')->nullable();
            $table->string('status', 16)->nullable();

            // Parent correspondence code, derived from this unit's own code and
            // level width. Recorded so roll-ups do not have to re-derive it, and
            // so an unresolved parent can be reported rather than assumed.
            $table->string('parent_correspondence_code', 9)->nullable();

            $table->timestamps();

            // A code is unique within a release, and the lookup path is always
            // release-scoped.
            $table->unique(['release_id', 'psgc10']);
            $table->index(['level', 'parent_correspondence_code']);
            $table->index('correspondence_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_units');
    }
};
