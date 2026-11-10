<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row for every commit imported from the clone's git history (ImportCommits): who wrote it,
     * when, its subject (the first line of the message) and how much it changed.
     *
     * - parent_sha is the first parent (null for the very first commit); is_merge marks a commit
     *   with two or more parents, for which git shows no changes, so its counters stay null;
     * - insertions and deletions are null when git could not count the lines
     *   (see CommitHistory: counting needs the file contents, which come from GitHub);
     * - a commit is imported once: (repository_id, sha) is unique, so importing again only updates it;
     * - the pages list the commits newest first, hence the index on (repository_id, authored_at).
     */
    public function up(): void
    {
        Schema::create('commits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('sha', 40);
            $table->string('parent_sha', 40)->nullable();
            $table->boolean('is_merge')->default(false);
            $table->string('author_name');
            $table->timestamp('authored_at');
            $table->string('subject', 500);
            $table->unsignedInteger('files_changed')->nullable();
            $table->unsignedInteger('insertions')->nullable();
            $table->unsignedInteger('deletions')->nullable();

            $table->unique(['repository_id', 'sha']);
            $table->index(['repository_id', 'authored_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commits');
    }
};
