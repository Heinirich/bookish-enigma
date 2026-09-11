<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // github | slack | jira | notion
            $table->string('driver')->default('fake'); // api | fake

            // Live API tokens. Stored as an encrypted blob rather than jsonb:
            // the `encrypted:array` cast emits a ciphertext string, and secrets
            // should not be readable to anyone who gets a look at the database.
            $table->text('credentials')->nullable();

            $table->timestampTz('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();  // ok | failed
            $table->text('last_test_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_settings');
    }
};
