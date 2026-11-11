<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every version of a page or of a diagram remembers the processing run that wrote it. The time travel
     * (Faza 9) shows a commit as it was right after its run: for each page and diagram, the newest version
     * written by a run that is not newer than that one. Run ids only grow, so this needs no dates, and
     * pages written by a run that failed later (they stayed on the site) are counted where they belong.
     *
     * The versions that already exist get the newest run of their repository that had started when the
     * version was written: only one run works at a time, so that is the run that wrote it.
     */
    public function up(): void
    {
        foreach (['document_versions' => 'document_id', 'diagram_versions' => 'repository_id'] as $table => $after) {
            Schema::table($table, function (Blueprint $table) use ($after) {
                $table->foreignId('processing_run_id')->nullable()->after($after)->constrained()->nullOnDelete();
            });
        }

        $runs = DB::table('processing_runs')->orderBy('id')->get(['id', 'repository_id', 'started_at'])->groupBy('repository_id');

        $runOf = function (int $repositoryId, ?string $createdAt) use ($runs): ?int {
            if ($createdAt === null) {
                return null;
            }

            // created_at has whole seconds, started_at has milliseconds: one second of tolerance.
            $writtenAt = Carbon::parse($createdAt)->addSecond();

            return $runs->get($repositoryId)?->last(fn (object $run): bool => Carbon::parse($run->started_at)->lessThanOrEqualTo($writtenAt))?->id;
        };

        DB::table('document_versions')
            ->join('documents', 'documents.id', '=', 'document_versions.document_id')
            ->orderBy('document_versions.id')
            ->get(['document_versions.id', 'documents.repository_id', 'document_versions.created_at'])
            ->each(fn (object $version) => DB::table('document_versions')->where('id', $version->id)->update([
                'processing_run_id' => $runOf($version->repository_id, $version->created_at),
            ]));

        DB::table('diagram_versions')
            ->orderBy('id')
            ->get(['id', 'repository_id', 'created_at'])
            ->each(fn (object $version) => DB::table('diagram_versions')->where('id', $version->id)->update([
                'processing_run_id' => $runOf($version->repository_id, $version->created_at),
            ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['document_versions', 'diagram_versions'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('processing_run_id');
            });
        }
    }
};
