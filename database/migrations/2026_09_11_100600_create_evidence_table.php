<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained()->cascadeOnDelete();

            // Public handle shown to the model, e.g. "EV-1". Unique per investigation:
            // the validator's existence gate is a lookup on this pair.
            $table->string('public_id', 16);

            $table->string('source');       // healthz | github | slack | jira | notion | correlator
            $table->string('kind');         // metric_series | deployment | commit | diff | changepoint | message | issue
            $table->string('source_ref')->nullable();
            $table->string('source_url')->nullable();
            $table->string('summary');
            $table->jsonb('payload');
            $table->string('hash', 64);
            $table->timestampTz('fetched_at');
            $table->timestamps();

            $table->unique(['investigation_id', 'public_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence');
    }
};
