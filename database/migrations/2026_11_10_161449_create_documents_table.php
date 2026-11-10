<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row for every page of the generated documentation (Faza 6): the overview ("overview"),
     * the generated README ("readme") and one page per module (slug made from the module's path).
     *
     * - module_id points to the module of a module page; the analysis rewrites the modules on every run,
     *   so the link is set to null when its module is deleted and the documentation job sets it again;
     * - input_hash is the sha256 of what the writer received (App\Documentation\ContextBuilder): when it
     *   has not changed, the page is not written again (this is what makes the incremental update cheap);
     * - writer is "ai" (Claude wrote it) or "template" (written from the analysis, without AI);
     *   ai_driver says how Claude was called ("claude-code" = the subscription, "claude" = the API);
     * - is_stale + stale_reason: the page is older than the code, or Claude failed and the template
     *   text stands in for it until the next run.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug');
            $table->string('title', 1024);
            $table->longText('markdown');
            $table->string('source_sha', 40)->nullable();
            $table->string('input_hash', 64);
            $table->string('writer', 20);
            $table->string('model')->nullable();
            $table->string('ai_driver', 20)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd_equivalent', 10, 6)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->boolean('is_stale')->default(false);
            $table->text('stale_reason')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'slug']);
            $table->index('module_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
