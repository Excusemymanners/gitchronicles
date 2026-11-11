<?php

namespace App\Search;

use App\Models\CodeChunk;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * Finds the pieces of code (code_chunks) that best match a question (Faza 8).
 *
 * The chunks themselves are rows of code_chunks (written by CodeIndexer); an implementation may keep
 * its own index of them, which it rebuilds and deletes explicitly. AppServiceProvider picks one:
 *
 * - SqliteCodeSearch: SQLite's full-text index (FTS5), ranked with bm25;
 * - LikeCodeSearch: plain SQL (LIKE) that works on any database, for the others (e.g. PostgreSQL).
 */
interface CodeSearch
{
    /**
     * Rebuild the index of a repository from its current code_chunks rows, replacing the old index.
     */
    public function rebuild(Repository $repository): void;

    /**
     * Bring the index in line with the repository's code_chunks rows after only some of them changed
     * (the incremental update, Faza 9): the entries of deleted chunks are removed and the new chunks
     * are added, without touching the rest.
     */
    public function sync(Repository $repository): void;

    /**
     * Delete the index of a repository (when the repository is deleted).
     */
    public function forget(Repository $repository): void;

    /**
     * The chunks of the repository that best match the terms, best first, with their file loaded.
     * No terms: no chunks.
     *
     * @param  list<string>  $terms  lowercase words without diacritics (see SearchTerms), each matched as a prefix
     * @param  list<string>  $paths  when not empty, only these files and folders are searched (see CodeChunk::inPaths())
     * @return Collection<int, CodeChunk>
     */
    public function search(Repository $repository, array $terms, int $limit, array $paths = []): Collection;
}
