<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            // What to do about it. A field rather than a prompt request, for the
            // same reason `mechanism` is one: asked for inside prose it gets
            // dropped, and the investigation stops one step short of useful.
            $table->text('remediation')->nullable()->after('mechanism');
        });
    }

    public function down(): void
    {
        Schema::table('hypotheses', function (Blueprint $table) {
            $table->dropColumn('remediation');
        });
    }
};
