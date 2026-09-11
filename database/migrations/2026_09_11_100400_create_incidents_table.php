<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('title');
            $table->text('prompt')->nullable();
            $table->foreignId('monitored_endpoint_id')->nullable()->constrained()->nullOnDelete();

            $table->string('trigger')->default('auto');   // auto | manual | eval
            $table->string('severity')->default('sev3');
            $table->string('status')->default('open');    // open | investigating | resolved

            $table->timestampTz('detected_at');
            $table->timestampTz('window_start');
            $table->timestampTz('window_end');

            $table->jsonb('detection_signal')->nullable();
            $table->string('scenario_key')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
