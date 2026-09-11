<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_endpoint_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('checked_at');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('latency_ms');
            $table->boolean('is_error')->default(false);
            $table->string('error_message')->nullable();
            $table->string('deploy_sha', 64)->nullable();
            $table->jsonb('checks')->nullable();

            $table->index(['monitored_endpoint_id', 'checked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
