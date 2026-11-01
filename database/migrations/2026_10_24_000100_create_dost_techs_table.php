<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The DOST technology catalogue for NCR.
     *
     * One row per program/project in the NCR DOST Deployed Technologies
     * masterlist "Technology Catalog" sheet: what the intervention is, who
     * implements it, where in NCR it is deployed, how it works and what it
     * demonstrably achieves.
     *
     * This is the reference catalogue the per-barangay deployment records
     * (the masterlist's other sheet) point back to via `code`, so it is
     * imported first.
     *
     * Imported from storage/app/NCR_DOST_Deployed_Technologies_Masterlist.xlsx
     * via `dost:import-tech-catalog`.
     */
    public function up(): void
    {
        Schema::create('dost_techs', function (Blueprint $table) {
            $table->id();

            // Program / Project Code, e.g. SETUP-FOOD-01. Unique per catalogue.
            $table->string('code')->unique();
            $table->string('name');

            // Lead DOST Implementing Agency, e.g. DOST-ITDI.
            $table->string('agency')->nullable();

            // Target Sector / Domain, e.g. Food Processing MSME.
            $table->string('sector')->nullable();

            // Primary NCR Deployment Clusters, a comma separated list of the
            // cities/barangays this intervention reaches.
            $table->text('deployment_clusters')->nullable();

            // Description of Technical Mechanism: how the technology works.
            $table->text('mechanism')->nullable();

            // Status in NCR, e.g. "Active / Deployed".
            $table->string('status')->nullable();

            // Measurable Outcome / Benefit: the demonstrated effect.
            $table->text('outcome')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dost_techs');
    }
};
