<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Curated catalogue of DOST science, technology and innovation resources.
     *
     * This is the evidence base for executive output 3, Recommended
     * Interventions. Without it the system has nothing legitimate to
     * recommend; inventing interventions is explicitly out of scope.
     *
     * Imported from the KonektaDOST workbook via `konekta:import-workbook`.
     */
    public function up(): void
    {
        Schema::create('technologies', function (Blueprint $table) {
            $table->id();

            // Workbook identifier, e.g. RES-001.
            $table->string('code')->unique();
            $table->string('name');
            $table->string('agency')->nullable();

            // Science Learning, Research & Innovation, Testing & Technical,
            // MSME & Livelihood, Health & Nutrition, Climate & DRR.
            $table->string('category')->nullable();

            // Empty in every current record, kept so the source column is not
            // silently dropped if it is filled in later.
            $table->string('duration_date')->nullable();

            $table->text('target_audience')->nullable();
            $table->string('delivery_mode')->nullable();
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->text('tags')->nullable();
            $table->string('active_status')->nullable();
            $table->string('source_reference')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technologies');
    }
};
