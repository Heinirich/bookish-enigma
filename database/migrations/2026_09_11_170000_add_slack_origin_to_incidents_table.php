<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            // Set when an investigation is started by a Slack slash command, so
            // the findings return to the conversation that asked for them rather
            // than to a new channel nobody is watching.
            $table->string('slack_channel_id')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('slack_channel_id');
        });
    }
};
