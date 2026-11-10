<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row for every file the analysis read: its language, its size and the git object id
     * of its content (blob_sha). The incremental update (Faza 9) compares blob_sha to find
     * the files that really changed.
     */
    public function up(): void
    {
        Schema::create('repository_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path', 1024);
            $table->string('language', 50)->nullable();
            $table->unsignedInteger('lines')->default(0);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('blob_sha', 40);

            $table->unique(['repository_id', 'path']);
            $table->index('module_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_files');
    }
};
