<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The public GitHub repositories GitChronicle watches for the „Descoperă” leaderboard (bonus), found by
     * `php artisan discover:refresh` with the GitHub Search API. They are not the `repositories` table:
     * those are the repositories a user asked GitChronicle to document; these are only candidates.
     *
     * - github_id: GitHub's own id, which never changes (the full name changes when a repository is renamed
     *   or moves to another owner), so it is the key of the upsert;
     * - full_name: "owner/name", as GitHub writes it (any case);
     * - description, language, topics, license, stars, forks: what GitHub said the last time it was seen;
     * - github_created_at, github_pushed_at: when the repository was created and last pushed to, on GitHub;
     * - first_seen_at: when GitChronicle first saw it; last_seen_at: when GitHub last told us its stars.
     */
    public function up(): void
    {
        Schema::create('watched_repositories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('github_id')->unique();
            $table->string('full_name')->index();
            $table->text('description')->nullable();
            $table->string('language', 100)->nullable()->index();
            $table->json('topics')->nullable();
            $table->string('license', 100)->nullable();
            $table->unsignedInteger('stars')->default(0);
            $table->unsignedInteger('forks')->default(0);
            $table->timestamp('github_created_at');
            $table->timestamp('github_pushed_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('watched_repositories');
    }
};
