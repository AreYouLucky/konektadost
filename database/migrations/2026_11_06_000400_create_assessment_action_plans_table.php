<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned snapshots of the action plans generated on the location assessment page.
 *
 * The location is stored as level + id + display fields without a foreign key, because the
 * geography tables are imported from a dump rather than migrated (see CLAUDE.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16);
            $table->unsignedBigInteger('location_id');
            $table->string('psg_code', 20)->nullable();
            $table->string('location_name');
            $table->string('province_name')->nullable();
            $table->string('focus', 40)->default('general');
            // Query string that reopens the live assessment this plan was generated from.
            $table->json('assessment_query');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->string('status')->default('draft_pending_human_validation');
            $table->boolean('ai_assisted')->default(false);
            $table->char('content_hash', 64);
            $table->longText('snapshot');
            $table->timestamps();
            $table->unique(['user_id', 'level', 'location_id', 'version'], 'assessment_action_plans_version_unique');
            $table->index(['user_id', 'level', 'location_id', 'content_hash'], 'assessment_action_plans_hash_index');
            $table->index(['user_id', 'created_at'], 'assessment_action_plans_recent_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_action_plans');
    }
};
