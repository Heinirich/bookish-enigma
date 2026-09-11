<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commits', function (Blueprint $table) {
            /*
            | The actual changed lines, as GitHub returns them.
            |
            | Seeded scenarios previously carried a prose diff_summary that named
            | the defect outright ("without an index on orders.payment_id"), which
            | meant the agent could identify a root cause by reading a label rather
            | than by reading code -- and made the evaluation worth very little.
            */
            $table->text('patch')->nullable()->after('diff_summary');
        });
    }

    public function down(): void
    {
        Schema::table('commits', function (Blueprint $table) {
            $table->dropColumn('patch');
        });
    }
};
