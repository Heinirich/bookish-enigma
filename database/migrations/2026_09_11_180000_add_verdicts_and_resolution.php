<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop the project is built around.
 *
 * Until now accuracy could only be measured against seeded scenarios, where the
 * answer was known because we planted it. A human verdict on a real incident is
 * the only way to find out whether a documented conclusion was actually right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            // null = nobody has judged it yet, which is distinct from being wrong.
            $table->string('verdict')->nullable()->after('status'); // confirmed | refuted
            $table->text('verdict_note')->nullable()->after('verdict');
            $table->string('verdict_by')->nullable()->after('verdict_note');
            $table->timestampTz('verdict_at')->nullable()->after('verdict_by');

            $table->index('verdict');
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->timestampTz('resolved_at')->nullable()->after('status');
            $table->text('resolution_note')->nullable()->after('resolved_at');
            $table->string('resolved_by')->nullable()->after('resolution_note');
            // What actually turned out to be responsible, which may be nothing the
            // agent proposed.
            $table->string('actual_cause_sha', 64)->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            $table->dropIndex(['verdict']);
            $table->dropColumn(['verdict', 'verdict_note', 'verdict_by', 'verdict_at']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn(['resolved_at', 'resolution_note', 'resolved_by', 'actual_cause_sha']);
        });
    }
};
