<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The presentation video and the slides of a repository (bonus), made on the "media" queue after
     * the repository is ready. One row per repository and kind ("video" or "slides", App\Enums\MediaKind),
     * reused at every regeneration.
     *
     * - status: pending → running → ready, or failed with a message for the page in "error";
     * - path: the main file on the "local" disk (media/{repository_id}/video.mp4 or slides.pdf);
     * - source_sha: the commit the media was made from, so nothing is made again for the same commit;
     * - duration_ms (video only) and size_bytes (of the main file);
     * - meta: the other files (poster, captions, pptx), the scenes with their sources, who wrote the script.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->string('path')->nullable();
            $table->string('source_sha', 40)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
