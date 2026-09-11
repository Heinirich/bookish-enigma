<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commits', function (Blueprint $table) {
            $table->id();
            $table->string('sha', 64)->unique();
            $table->string('repo');
            $table->text('message');
            $table->string('author')->nullable();
            $table->timestampTz('committed_at')->index();
            $table->jsonb('changed_files')->default('[]');
            $table->unsignedInteger('additions')->default(0);
            $table->unsignedInteger('deletions')->default(0);
            $table->text('diff_summary')->nullable();
            $table->string('html_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commits');
    }
};
