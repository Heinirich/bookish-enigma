<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('model');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedSmallInteger('scenario_count')->default(0);

            $table->decimal('root_cause_hit_rate', 4, 3)->nullable();
            $table->decimal('evidence_real_rate', 4, 3)->nullable();
            $table->decimal('grounding_rate', 4, 3)->nullable();
            $table->decimal('brier_score', 4, 3)->nullable();
            $table->decimal('action_completeness', 4, 3)->nullable();

            $table->timestamps();
        });

        Schema::create('evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investigation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scenario_key');

            $table->boolean('root_cause_hit')->default(false);
            $table->string('expected_sha', 64)->nullable();
            $table->string('predicted_sha', 64)->nullable();
            $table->decimal('top_confidence', 4, 3)->nullable();

            $table->unsignedSmallInteger('cited_count')->default(0);
            $table->unsignedSmallInteger('fabricated_count')->default(0);
            $table->decimal('grounding_rate', 4, 3)->nullable();
            $table->decimal('brier', 4, 3)->nullable();
            $table->jsonb('actions_completed')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluation_runs');
    }
};
