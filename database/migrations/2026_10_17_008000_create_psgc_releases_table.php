<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per PSA PSGC publication datafile.
 *
 * The PSGC is republished quarterly and the codes change between releases, so
 * a fact is only interpretable alongside the release it came from. Recording
 * the file name and its checksum means a later import can prove it read the
 * same bytes, and a diff between two releases can be attributed to PSA rather
 * than to a change in our own processing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psgc_releases', function (Blueprint $table) {
            $table->id();
            $table->string('label')->unique();
            $table->date('publication_date')->nullable();
            $table->string('source_filename');
            $table->string('source_sha256', 64);
            // Rows physically present in the datafile, units accepted after
            // validation, and rows rejected. Kept apart so an import that
            // silently drops a fifth of the file is visible.
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('units_accepted')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psgc_releases');
    }
};
