<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hypothesis_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hypothesis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evidence_id')->constrained('evidence')->cascadeOnDelete();
            $table->string('relation')->default('supports'); // supports | contradicts
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['hypothesis_id', 'evidence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hypothesis_evidence');
    }
};
