<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registry of every data source behind a finding.
     *
     * This is the provenance layer: each assessment finding cites one or more
     * source codes so a planner can open the URL and check the date.
     *
     * Note Source ID alone is NOT unique: S01 appears once for the CAR dataset
     * and again for MIMAROPA. Records are therefore unique on code + dataset.
     */
    public function up(): void
    {
        Schema::create('evidence_sources', function (Blueprint $table) {
            $table->id();

            // Workbook identifier, e.g. S01. Repeats across regions.
            $table->string('code')->index();

            $table->string('publisher')->nullable();
            $table->string('dataset', 500);
            $table->string('url')->nullable();
            $table->text('description')->nullable();
            $table->date('source_checked')->nullable();
            $table->timestamps();

            $table->unique(['code', 'dataset'], 'evidence_sources_code_dataset_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_sources');
    }
};
