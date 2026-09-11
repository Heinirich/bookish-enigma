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
            | cause | ruled_out
            |
            | Without this the schema offered only a causal frame, so the model
            | expressed exclusions as "the spike is caused by X" at 0.00
            | confidence -- a statement that contradicts itself and reads badly
            | in every report it appears in.
            */
            $table->string('stance')->default('cause')->after('statement');
        });
    }

    public function down(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            $table->dropColumn('stance');
        });
    }
};
