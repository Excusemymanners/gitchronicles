<?php

namespace App\Git;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * What changed in a clone between two commits: the files added, modified and deleted, as
 * `git diff --name-status` lists them. The incremental update (Faza 9) starts from this list.
 *
 * --no-renames: a renamed file appears as one deleted and one added file. Finding renames would
 * need the old file contents, which a blobless clone does not have, and for the documentation a
 * rename is exactly that: the old path is gone and a new one appeared.
 *
 * The diff compares the two commits' folder listings (trees), which the clone always has, so it
 * never uses the network. It works also when the history was rewritten (a force push): the two
 * commits only have to exist in the clone.
 */
final readonly class ChangeSet
{
    /**
     * After this many seconds git is stopped; the diff reads only local data, so it is fast.
     */
    public const TIMEOUT_SECONDS = 60;

    /**
     * @param  list<string>  $added  paths that exist only at the new commit
     * @param  list<string>  $modified  paths whose content (or file mode) differs
     * @param  list<string>  $deleted  paths that exist only at the old commit
     */
    public function __construct(
        public array $added = [],
        public array $modified = [],
        public array $deleted = [],
    ) {}

    /**
     * The changes from commit $from to commit $to, or null when git cannot compare them
     * (the old commit is no longer in the clone, for example after it was cloned again from a
     * repository whose history was rewritten). The caller then processes everything.
     */
    public static function between(string $clonePath, string $from, string $to): ?self
    {
        // GIT_NO_LAZY_FETCH: never go to the network for a missing object, fail right away instead.
        $result = Git::process($clonePath, ['GIT_NO_LAZY_FETCH' => '1'])
            ->timeout(self::TIMEOUT_SECONDS)
            ->run(['git', 'diff', '--name-status', '--no-renames', '-z', $from, $to, '--']);

        if ($result->failed()) {
            Log::warning('git diff failed: the two commits cannot be compared.', [
                'clone' => $clonePath,
                'from' => $from,
                'to' => $to,
                'git_output' => Str::limit($result->errorOutput(), 1000),
            ]);

            return null;
        }

        return self::parse($result->output());
    }

    /**
     * Turn the output of `git diff --name-status -z` into a change set. With -z every status and
     * every path ends with NUL: "A\0new.php\0M\0old.php\0D\0gone.php\0". A status other than A and D
     * (M = modified, T = the file type changed) counts as modified.
     */
    public static function parse(string $output): self
    {
        $pieces = explode("\0", $output);
        $added = [];
        $modified = [];
        $deleted = [];

        for ($index = 0; $index + 1 < count($pieces); $index += 2) {
            $path = $pieces[$index + 1];

            match ($pieces[$index][0] ?? '') {
                'A' => $added[] = $path,
                'D' => $deleted[] = $path,
                default => $modified[] = $path,
            };
        }

        return new self($added, $modified, $deleted);
    }

    /**
     * Every changed path, whatever happened to it.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return [...$this->added, ...$this->modified, ...$this->deleted];
    }

    /**
     * How many files changed.
     */
    public function count(): int
    {
        return count($this->added) + count($this->modified) + count($this->deleted);
    }

    /**
     * Whether nothing changed.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }
}
