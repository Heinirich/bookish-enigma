<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');

            $table->string('tool');
            $table->string('phase');          // gather | explore | synthesize | act
            $table->jsonb('arguments')->nullable();
            $table->text('result_summary')->nullable();
            $table->jsonb('result')->nullable();

            $table->string('status')->default('succeeded'); // succeeded | failed | pending_approval | rejected
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Write actions touch real Slack/Jira/Notion, so they are gated and audited.
            $table->boolean('was_write')->default(false);
            $table->timestampTz('approved_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->string('external_ref')->nullable();
            $table->string('external_url')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->index(['investigation_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_actions');
    }
};
