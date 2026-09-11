<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->string('sha', 64)->index();
            $table->string('repo');
            $table->string('ref')->default('main');
            $table->string('environment')->default('production');
            $table->string('release_name')->nullable();
            $table->string('author')->nullable();
            $table->timestampTz('deployed_at')->index();
            $table->string('html_url')->nullable();

            // Demo/eval machinery: a chaos scenario deploy carries the profile it activated
            // and, when seeded, the ground truth the agent is never shown.
            $table->jsonb('chaos_profile')->nullable();
            $table->boolean('is_seeded_cause')->default(false);
            $table->string('scenario_key')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
