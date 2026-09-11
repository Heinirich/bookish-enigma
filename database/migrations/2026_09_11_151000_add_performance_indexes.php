<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Postgres does not index a foreign key column automatically, and several of
 * these are read on every page load or every scheduler tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->index('incident_id');
            // Read every detection tick by the hourly investigation cap.
            $table->index('created_at');
        });

        Schema::table('hypotheses', function (Blueprint $table) {
            $table->index('investigation_id');
        });

        Schema::table('evaluation_scores', function (Blueprint $table) {
            $table->index('evaluation_run_id');
        });
    }

    public function down(): void
    {
        Schema::table('investigations', function (Blueprint $table) {
            $table->dropIndex(['incident_id']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('hypotheses', function (Blueprint $table) {
            $table->dropIndex(['investigation_id']);
        });

        Schema::table('evaluation_scores', function (Blueprint $table) {
            $table->dropIndex(['evaluation_run_id']);
        });
    }
};
