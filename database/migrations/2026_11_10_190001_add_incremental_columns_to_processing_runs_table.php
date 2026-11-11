<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What an incremental run (Faza 9) remembers besides its counters:
     *
     * - "change_summary": the summary of what changed between from_sha and to_sha: how many files were added,
     *   modified and deleted, the affected modules (each with its number of changed files and how the
     *   change reached it) and, for a full run, why it was not incremental. The „Istoric” tab shows it;
     * - "module_paths": the modules found by the code analysis at this commit. The time travel compares
     *   this list with the current one to say which modules appeared or disappeared since;
     * - "documents_kept": the pages left as they were (documents_regenerated counts the written ones);
     * - "context_characters": how many characters the writer received (or, without AI, would have
     *   received) for the pages written in this run. Unlike the tokens, it is measured without AI too.
     *
     * The newest successful run of every analyzed repository gets its module list right here: the modules
     * table still holds exactly the analysis of that run's commit.
     */
    public function up(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->json('change_summary')->nullable()->after('modules_affected');
            $table->json('module_paths')->nullable()->after('change_summary');
            $table->unsignedInteger('documents_kept')->default(0)->after('documents_regenerated');
            $table->unsignedBigInteger('context_characters')->default(0)->after('documents_kept');
        });

        $repositories = DB::table('repositories')->whereNotNull('analyzed_at')->whereNotNull('last_sha')->get(['id', 'last_sha']);

        foreach ($repositories as $repository) {
            $runId = DB::table('processing_runs')
                ->where('repository_id', $repository->id)
                ->where('status', 'succeeded')
                ->where('to_sha', $repository->last_sha)
                ->max('id');

            if ($runId !== null) {
                DB::table('processing_runs')->where('id', $runId)->update([
                    'module_paths' => json_encode(
                        DB::table('modules')->where('repository_id', $repository->id)->orderBy('path')->pluck('path')->all(),
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                    ),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('processing_runs', function (Blueprint $table) {
            $table->dropColumn(['change_summary', 'module_paths', 'documents_kept', 'context_characters']);
        });
    }
};
