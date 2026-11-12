<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The „Consultanță” report of a repository (bonus): what Semgrep, OSV-Scanner and scc found in its clone
     * at one commit. One row per repository and commit, reused when the same commit is analyzed again;
     * only the newest few are kept (ConsultingScanner).
     *
     * - status: pending → running → ready, or failed with a reason code in "error" (clone_missing,
     *   not_installed, no_tool_ran, unexpected), worded by the page;
     * - tools: for each tool its state (ran, not installed, failed, timed out), version, duration, how many
     *   findings it had and how many were left out by the cap, its failure as a code with a number
     *   (timed_out 300, exit_code 2, …) and a few details (files or packages scanned);
     * - code_statistics: the languages counted by scc (files, lines, code, comments, complexity);
     * - counts: the findings by severity and kind, ready for the summary at the top of the page;
     * - summary / summary_meta: the short executive summary written by AI in English from these numbers only
     *   (null when AI is off or its answer was rejected) and what that call used.
     */
    public function up(): void
    {
        Schema::create('consulting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->string('source_sha', 40)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error', 50)->nullable();
            $table->json('tools')->nullable();
            $table->json('code_statistics')->nullable();
            $table->json('counts')->nullable();
            $table->unsignedInteger('finding_count')->default(0);
            $table->unsignedInteger('omitted_count')->default(0);
            $table->text('summary')->nullable();
            $table->json('summary_meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['repository_id', 'source_sha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consulting_reports');
    }
};
