<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hypotheses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();
            $table->text('statement');
            $table->decimal('confidence', 4, 3);
            $table->unsignedSmallInteger('rank');
            $table->foreignId('root_cause_deployment_id')->nullable()->constrained('deployments')->nullOnDelete();
            $table->string('root_cause_sha', 64)->nullable();

            // accepted | rejected_unsupported | repaired
            $table->string('status')->default('accepted');

            $table->decimal('grounding_score', 4, 3)->nullable();
            $table->jsonb('validation_report')->nullable();
            $table->jsonb('raw_evidence_ids')->nullable(); // exactly what the model claimed, pre-validation

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypotheses');
    }
};
