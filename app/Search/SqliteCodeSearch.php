<?php

namespace App\Search;

use App\Models\CodeChunk;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The code search on SQLite: a full-text index (FTS5, the code_chunks_fts table) ranked with bm25,
 * the classic search-engine score (a rare word that appears often in a short chunk counts most).
 *
 * The index is kept in sync explicitly, never by triggers: rebuild() writes one row for every chunk
 * (rowid = the chunk's id) and forget() deletes the repository's rows. A row holds the chunk's path,
 * its symbol name and its code, all with their identifiers split into words (SearchTerms::splitIdentifiers),
 * and FTS5's "unicode61 remove_diacritics 2" tokenizer ignores case and diacritics.
 *
 * A match in the path counts 4 times and a match in the symbol name 6 times more than one in the code:
 * a question about „autentificare” should bring LoginController before a file that only mentions "login".
 */
final class SqliteCodeSearch implements CodeSearch
{
    /**
     * The bm25 weights of the indexed columns: path, symbol_name, body.
     */
    private const COLUMN_WEIGHTS = '4.0, 6.0, 1.0';

    /**
     * How many index rows one INSERT writes.
     */
    private const INSERT_CHUNK_SIZE = 500;

    /**
     * Rebuild the index of a repository from its code_chunks rows.
     */
    public function rebuild(Repository $repository): void
    {
        $this->forget($repository);

        $repository->codeChunks()
            ->with('repositoryFile:id,path')
            ->chunkById(self::INSERT_CHUNK_SIZE, function (EloquentCollection $chunks) use ($repository): void {
                DB::table('code_chunks_fts')->insert($chunks->map(fn (CodeChunk $chunk): array => [
                    'rowid' => $chunk->id,
                    'path' => SearchTerms::splitIdentifiers($chunk->repositoryFile->path),
                    'symbol_name' => SearchTerms::splitIdentifiers($chunk->symbol_name ?? ''),
                    'body' => SearchTerms::splitIdentifiers($chunk->content),
                    'repository_id' => $repository->id,
                ])->all());
            });
    }

    /**
     * Delete the repository's rows from the index, also those whose chunks were already deleted
     * (a new code analysis deletes the files, and their chunks with them).
     */
    public function forget(Repository $repository): void
    {
        DB::table('code_chunks_fts')->where('repository_id', $repository->id)->delete();
    }

    /**
     * The best chunks for the terms. Each term is searched as a prefix ("modul" finds "module")
     * and a chunk matches when it has at least one of them (OR): bm25 ranks first the chunks
     * that match more terms, rarer terms and the path or symbol name.
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

        // Every term is quoted, so a word like "NOT" or a quote can never become FTS5 syntax.
        $query = collect($terms)
            ->map(fn (string $term): string => '"'.str_replace('"', '""', $term).'"*')
            ->implode(' OR ');

        // CROSS JOIN makes SQLite read the index first and look up each match's chunk by its id.
        // With a plain JOIN it may walk every chunk of the repository and run the full-text search
        // again for each one (minutes instead of milliseconds on a big repository).
        return CodeChunk::query()
            ->select('code_chunks.*')
            ->selectRaw('bm25(code_chunks_fts, '.self::COLUMN_WEIGHTS.') as score')
            ->from('code_chunks_fts')
            ->crossJoin('code_chunks', 'code_chunks.id', '=', 'code_chunks_fts.rowid')
            ->whereRaw('code_chunks_fts MATCH ?', [$query])
            ->where('code_chunks_fts.repository_id', $repository->id)
            ->when($paths !== [], fn (Builder $query) => $query->inPaths($paths))
            ->orderBy('score')
            ->orderBy('code_chunks.id')
            ->limit($limit)
            ->with('repositoryFile:id,path')
            ->get();
    }
}
