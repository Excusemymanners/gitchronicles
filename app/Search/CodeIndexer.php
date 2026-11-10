<?php

namespace App\Search;

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
 * It is idempotent: in one transaction the repository's old chunks are deleted and the new ones saved,
 * so the assistant sees either the old index or the new one, never half of it.
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
     * Create a new indexer.
     */
    public function __construct(private CodeSearch $search) {}

    /**
     * Replace the repository's chunks and search index with new ones made from its clone.
     *
     * @return array{files_indexed: int, chunks_total: int}
     */
    public function index(Repository $repository): array
    {
        return DB::transaction(function () use ($repository): array {
            $repository->codeChunks()->delete();

            $filesIndexed = 0;
            $chunksTotal = 0;

            $repository->files()
                ->with('symbols:id,repository_file_id,name,start_line,end_line')
                ->chunkById(self::BATCH_SIZE, function (Collection $files) use ($repository, &$filesIndexed, &$chunksTotal): void {
                    $rows = [];

                    foreach ($files as $file) {
                        $content = $this->readableContent($repository, $file);

                        if ($content === null) {
                            continue;
                        }

                        $filesIndexed++;

                        foreach (CodeChunker::chunk($content, $file->symbols->map->only(['name', 'start_line', 'end_line'])->all()) as $chunk) {
                            $rows[] = [...$chunk, 'repository_id' => $repository->id, 'repository_file_id' => $file->id];
                        }
                    }

                    foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
                        CodeChunk::insert($batch);
                    }

                    $chunksTotal += count($rows);
                });

            $this->search->rebuild($repository);

            return ['files_indexed' => $filesIndexed, 'chunks_total' => $chunksTotal];
        });
    }

    /**
     * The text of a file from the clone, or null when the file should not be indexed.
     * A symbolic link is never followed (it could point outside the clone).
     */
    private function readableContent(Repository $repository, RepositoryFile $file): ?string
    {
        $path = $repository->clonePath().'/'.$file->path;

        if (in_array(strtolower(pathinfo($file->path, PATHINFO_EXTENSION)), self::SKIPPED_EXTENSIONS, true)
            || is_link($path)
            || ! is_file($path)
            || filesize($path) > self::MAX_FILE_BYTES) {
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
