<?php

namespace App\Search;

use App\Git\ChangeSet;
use App\Models\CodeChunk;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prepares a repository's code for the assistant (Faza 8): every file saved by the code analysis
 * is read from the clone and cut into chunks (CodeChunker), the chunks are saved in code_chunks,
 * and then the search index is rebuilt from them (CodeSearch::rebuild()).
 *
 * Files that are not code someone reads are skipped: over 200 KB, with a line over 2,000 characters
 * (generated or minified), not UTF-8 text, or images and source maps written as text (.svg, .map).
 * The analysis already left out binary files, libraries, lock files and files over 1 MB.
 *
 * It is idempotent: the old chunks of a group of files are replaced by the new ones in one short transaction.
 *
 * index() does this for the whole repository; reindex() (the incremental update, Faza 9) only for the
 * files that changed between two commits, leaving the chunks of every other file as they are.
 */
final class CodeIndexer
{
    /**
     * Bigger files are data or generated code, not code to cite.
     */
    public const MAX_FILE_BYTES = 200 * 1024;

    /**
     * A file with a longer line is generated or minified.
     */
    public const MAX_LINE_LENGTH = 2000;

    /**
     * Text files that are not code: drawings (SVG) and source maps.
     */
    private const SKIPPED_EXTENSIONS = ['svg', 'map'];

    /**
     * How many files are read between two database queries, and how many chunks one INSERT saves.
     */
    private const BATCH_SIZE = 200;

    /**
     * How many files are read, cut and replaced together (one short transaction each).
     */
    private const READ_BATCH_SIZE = 50;

    /**
     * Create a new indexer.
     */
    public function __construct(private CodeSearch $search) {}

    /**
     * Replace the repository's chunks and search index with new ones made from its clone.
     *
     * The files are handled in small groups: the group is read and cut into chunks first (the slow part, with
     * no lock on the database), then its old chunks are replaced in one short transaction. SQLite lets only one
     * writer in at a time, so a long transaction would block every page that saves something (a session, the
     * queue); this way the lock is held for a moment. Every file's chunks change at once, never half of them.
     * At most max_chunks chunks are kept (config gitchronicle.search); the files that did not fit are counted.
     *
     * @return array{files_indexed: int, chunks_total: int, files_left_out: int}
     */
    public function index(Repository $repository): array
    {
        $filesIndexed = 0;
        $chunksSaved = 0;
        $filesLeftOut = 0;

        $repository->files()->chunkById(self::READ_BATCH_SIZE, function (Collection $files) use ($repository, &$filesIndexed, &$chunksSaved, &$filesLeftOut): void {
            [$indexed, $chunks, $leftOut] = $this->replaceChunksOf($repository, $files, $chunksSaved);

            $filesIndexed += $indexed;
            $chunksSaved += $chunks;
            $filesLeftOut += $leftOut;
        });

        $this->search->rebuild($repository);

        return ['files_indexed' => $filesIndexed, 'chunks_total' => $repository->codeChunks()->count(), 'files_left_out' => $filesLeftOut];
    }

    /**
     * Cut again only the files that changed: their old chunks are deleted and new ones are saved.
     * The chunks of a deleted file are already gone (they were deleted together with the file's row
     * by the code analysis), and the search index is then brought in line (CodeSearch::sync()).
     *
     * @return array{files_indexed: int, chunks_total: int, files_left_out: int}
     */
    public function reindex(Repository $repository, ChangeSet $changes): array
    {
        $filesIndexed = 0;
        $chunksSaved = $repository->codeChunks()->count();
        $filesLeftOut = 0;

        foreach (array_chunk([...$changes->added, ...$changes->modified], self::READ_BATCH_SIZE) as $paths) {
            $files = $repository->files()->whereIn('path', $paths)->get();

            [$indexed, $chunks, $leftOut] = $this->replaceChunksOf($repository, $files, $chunksSaved);

            $filesIndexed += $indexed;
            $chunksSaved += $chunks;
            $filesLeftOut += $leftOut;
        }

        $this->search->sync($repository);

        return ['files_indexed' => $filesIndexed, 'chunks_total' => $repository->codeChunks()->count(), 'files_left_out' => $filesLeftOut];
    }

    /**
     * Cut a group of files into chunks (no lock held), then replace their old chunks with the new ones in one short
     * transaction. A file whose chunks would pass the limit of chunks per repository is left out.
     *
     * @param  Collection<int, RepositoryFile>  $files
     * @param  int  $chunksAlready  the chunks the repository has after the earlier groups
     * @return array{int, int, int} files indexed, chunks saved, files left out because of the limit
     */
    private function replaceChunksOf(Repository $repository, Collection $files, int $chunksAlready): array
    {
        $maxChunks = (int) config('gitchronicle.search.max_chunks');
        $symbols = $this->symbolsOf($files);
        $rows = [];
        $filesIndexed = 0;
        $filesLeftOut = 0;

        foreach ($files as $file) {
            $content = $this->readableContent($repository, $file);

            if ($content === null) {
                continue;
            }

            $chunks = CodeChunker::chunk($content, $symbols[$file->id] ?? []);

            if ($maxChunks > 0 && $chunksAlready + count($rows) + count($chunks) > $maxChunks) {
                $filesLeftOut++;

                continue;
            }

            $filesIndexed++;

            foreach ($chunks as $chunk) {
                $rows[] = [...$chunk, 'repository_id' => $repository->id, 'repository_file_id' => $file->id];
            }
        }

        DB::transaction(function () use ($files, $rows): void {
            CodeChunk::whereIn('repository_file_id', $files->modelKeys())->delete();

            foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
                CodeChunk::insert($batch);
            }
        });

        return [$filesIndexed, count($rows), $filesLeftOut];
    }

    /**
     * The symbols of a group of files as plain arrays (no model per symbol: a file may have thousands).
     *
     * @param  Collection<int, RepositoryFile>  $files
     * @return array<int, list<array{name: string, start_line: int, end_line: int}>> by file id
     */
    private function symbolsOf(Collection $files): array
    {
        $symbols = [];

        DB::table('code_symbols')
            ->whereIn('repository_file_id', $files->modelKeys())
            ->orderBy('id')
            ->select(['repository_file_id', 'name', 'start_line', 'end_line'])
            ->each(function (object $symbol) use (&$symbols): void {
                $symbols[$symbol->repository_file_id][] = ['name' => $symbol->name, 'start_line' => $symbol->start_line, 'end_line' => $symbol->end_line];
            });

        return $symbols;
    }

    /**
     * The text of a file from the clone, or null when the file should not be indexed.
     * A symbolic link is never followed, neither the file nor a folder on the way (it could point outside the clone).
     */
    private function readableContent(Repository $repository, RepositoryFile $file): ?string
    {
        $path = $repository->clonePath().'/'.$file->path;

        if (in_array(strtolower(pathinfo($file->path, PATHINFO_EXTENSION)), self::SKIPPED_EXTENSIONS, true)
            || is_link($path)
            || ! is_file($path)
            || filesize($path) > self::MAX_FILE_BYTES
            || ! str_starts_with((string) realpath($path), (string) realpath($repository->clonePath()).'/')) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false
            || str_contains($content, "\0")
            || ! mb_check_encoding($content, 'UTF-8')
            || max(array_map(strlen(...), explode("\n", $content))) > self::MAX_LINE_LENGTH) {
            return null;
        }

        return $content;
    }
}
