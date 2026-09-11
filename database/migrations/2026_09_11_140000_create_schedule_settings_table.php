<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_settings', function (Blueprint $table) {
            $table->id();

            $table->boolean('monitoring_enabled')->default(true);
            $table->unsignedSmallInteger('ping_interval_seconds')->default(10);
            $table->unsignedSmallInteger('detect_interval_minutes')->default(1);

            // Whether a detected incident automatically starts an investigation,
            // or waits for someone to press the button.
            $table->boolean('auto_investigate')->default(true);

            $table->unsignedSmallInteger('incident_cooldown_minutes')->default(15);

            /*
            | Hard ceiling on automated runs. Each investigation is tens of seconds
            | of local inference and thousands of tokens; a flapping endpoint could
            | otherwise queue them faster than the worker drains them.
            */
            $table->unsignedSmallInteger('max_investigations_per_hour')->default(6);

            // Suppress automated investigation between these hours (UTC). Null disables.
            $table->unsignedTinyInteger('quiet_hours_start')->nullable();
            $table->unsignedTinyInteger('quiet_hours_end')->nullable();

            $table->timestampTz('last_ping_at')->nullable();
            $table->timestampTz('last_detect_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_settings');
    }
};
