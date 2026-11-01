<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registered data vintages, e.g. "2024_cph".
     *
     * The existing unique index on population_figures
     * (area_level, area_ref, vintage) already allows multiple vintages to
     * coexist. This table records where each one came from and marks which is
     * current, so assessments stop guessing.
     */
    public function up(): void
    {
        Schema::create('data_vintages', function (Blueprint $table) {
            $table->id();
            $table->string('vintage')->unique();
            $table->string('label')->nullable();
            $table->string('source')->default('psa');
            $table->string('source_url')->nullable();
            $table->smallInteger('census_year')->nullable();

            // Exactly one vintage should be current. Enforced in the model
            // rather than the schema, since MySQL has no partial unique index.
            $table->boolean('is_current')->default(false)->index();

            $table->timestamp('imported_at')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('imported_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_vintages');
    }
};
