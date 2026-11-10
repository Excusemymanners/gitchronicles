<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row for every file a commit changed (git log --numstat): its path and the lines added
     * and removed in it. The counts are null for a binary file (git cannot count its lines) and
     * when the line counts were not available at all.
     *
     * The „Zone fierbinți” count the commits of each path, hence the index on path;
     * commit_id is indexed for loading (and replacing) the files of a commit.
     */
    public function up(): void
    {
        Schema::create('commit_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commit_id')->constrained()->cascadeOnDelete();
            $table->string('path', 1024);
            $table->unsignedInteger('insertions')->nullable();
            $table->unsignedInteger('deletions')->nullable();

            $table->index('commit_id');
            $table->index('path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commit_files');
    }
};
