<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per CSV import attempt.
     *
     * Kept even when an import fails, so a bad file leaves a trail rather than
     * silently disappearing.
     */
    public function up(): void
    {
        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('data_vintage_id')->nullable();
            $table->string('filename');
            $table->string('vintage');
            $table->string('status')->default('preview'); // preview | committed | failed
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->text('messages')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->foreign('data_vintage_id')->references('id')->on('data_vintages')->nullOnDelete();
            $table->foreign('imported_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
    }
};
