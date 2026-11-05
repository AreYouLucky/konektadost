<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // NULL for archived conversations; nullable unique keys allow multiple archives.
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->json('agent_state')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'municipality_id', 'active_slot'], 'planning_conversations_active_unique');
            $table->index(['user_id', 'municipality_id', 'updated_at'], 'planning_conversations_history_index');
        });

        Schema::create('planning_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('planning_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->json('sources')->nullable();
            $table->uuid('request_id')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'request_id', 'role'], 'planning_messages_request_unique');
        });

        Schema::create('planning_action_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('planning_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('planning_messages')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->string('status')->default('draft_pending_human_validation');
            $table->longText('content');
            $table->json('evidence_snapshot')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'version']);
            $table->unique('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_action_plans');
        Schema::dropIfExists('planning_messages');
        Schema::dropIfExists('planning_conversations');
    }
};
