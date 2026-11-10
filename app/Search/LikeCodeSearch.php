<?php

namespace App\Search;

use App\Models\CodeChunk;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The code search for databases without SQLite's FTS5 (e.g. PostgreSQL, until it gets its own
 * full-text search): plain SQL that works everywhere. It keeps no index of its own.
 *
 * 1. SQL finds the chunks whose code, path or symbol name contains at least one term
 *    (lower(...) LIKE '%term%'), at most MAX_CANDIDATES of them;
 * 2. PHP scores them like SqliteCodeSearch weighs its columns: a term in the path counts 4,
 *    in the symbol name 6, and every occurrence in the code 1 (at most 5 per term).
 */
final class LikeCodeSearch implements CodeSearch
{
    /**
     * How many matching chunks are scored at most.
     */
    public const MAX_CANDIDATES = 300;

    /**
     * Nothing to rebuild: the search reads code_chunks directly.
     */
    public function rebuild(Repository $repository): void {}

    /**
     * Nothing to delete: the chunks are deleted with the repository (cascade).
     */
    public function forget(Repository $repository): void {}

    /**
     * The best chunks for the terms, scored in PHP.
     *
     * @param  list<string>  $terms
     * @param  list<string>  $paths
     * @return Collection<int, CodeChunk>
     */
    public function search(Repository $repository, array $terms, int $limit, array $paths = []): Collection
    {
        if ($terms === []) {
            return collect();
        }

        $candidates = CodeChunk::query()
            ->select('code_chunks.*')
            ->join('repository_files', 'repository_files.id', '=', 'code_chunks.repository_file_id')
            ->where('code_chunks.repository_id', $repository->id)
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%'.$this->escapeLike($term).'%';

                    $query->orWhereRaw("lower(code_chunks.content) like ? escape '!'", [$pattern])
                        ->orWhereRaw("lower(repository_files.path) like ? escape '!'", [$pattern])
                        ->orWhereRaw("lower(code_chunks.symbol_name) like ? escape '!'", [$pattern]);
                }
            })
            ->when($paths !== [], fn (Builder $query) => $query->inPaths($paths))
            ->orderBy('code_chunks.id')
            ->limit(self::MAX_CANDIDATES)
            ->with('repositoryFile:id,path')
            ->get();

        foreach ($candidates as $chunk) {
            $chunk->score = $this->score($chunk, $terms);
        }

        return $candidates
            ->sortBy([['score', 'desc'], ['id', 'asc']])
            ->take($limit)
            ->values()
            ->toBase();
    }

    /**
     * How well one chunk matches the terms (bigger is better).
     *
     * @param  list<string>  $terms
     */
    private function score(CodeChunk $chunk, array $terms): int
    {
        $path = Str::lower($chunk->repositoryFile->path);
        $symbolName = Str::lower($chunk->symbol_name ?? '');
        $content = Str::lower($chunk->content);

        $score = 0;

        foreach ($terms as $term) {
            $score += (str_contains($path, $term) ? 4 : 0)
                + (str_contains($symbolName, $term) ? 6 : 0)
                + min(substr_count($content, $term), 5);
        }

        return $score;
    }

    /**
     * Escape the LIKE wildcards of a term ("%" and "_"), with "!" as the escape character,
     * which means the same thing on SQLite, PostgreSQL and MySQL.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
