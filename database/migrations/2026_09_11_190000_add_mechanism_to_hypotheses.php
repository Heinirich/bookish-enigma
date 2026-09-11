<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            /*
            | Why the change produces the symptom, as its own field.
            |
            | Asking for it inside `statement` did not work: the model kept
            | emitting "Deploy abc1234 caused the latency spike" however firmly
            | the prompt asked for a mechanism. A dedicated slot in the schema is
            | grammar-enforced, so it cannot be skipped -- the same reason
            | evidence_ids is a field rather than a request to cite sources.
            */
            $table->text('mechanism')->nullable()->after('statement');
        });
    }

    public function down(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            $table->dropColumn('mechanism');
        });
    }
};
