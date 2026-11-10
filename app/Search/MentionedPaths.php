<?php

namespace App\Search;

use App\Models\Repository;
use Illuminate\Support\Str;

/**
 * Finds the folders and files of a repository that a question names, e.g. „Ce face modulul app/Http?”
 * or „Ce face LoginController.php?”, so the search can look only there.
 *
 * A candidate is a word with a "/" (a path) or a file name with an extension. It counts only when
 * the repository really has it: a folder becomes "app/Http/" (everything under it), a file its full path.
 * The real spelling from the database is used, so „app/http” finds "app/Http/".
 */
final class MentionedPaths
{
    /**
     * At most this many paths are kept (a file name can exist in several folders).
     */
    public const MAX_PATHS = 5;

    /**
     * A path ("app/Http", "src/lib/") or a file name with an extension ("web.php").
     */
    private const CANDIDATE_PATTERN = '~[\w.\-]+(?:/[\w.\-]+)+/?|[\w\-]+\.[A-Za-z]{1,5}\b~u';

    /**
     * The paths the question names, and the question without them (for the other search terms).
     *
     * @return array{paths: list<string>, rest: string}
     */
    public static function in(Repository $repository, string $question): array
    {
        preg_match_all(self::CANDIDATE_PATTERN, $question, $matches);

        $paths = [];
        $rest = $question;

        foreach (array_unique($matches[0]) as $candidate) {
            $found = self::resolve($repository, self::clean($candidate));

            if ($found !== []) {
                array_push($paths, ...$found);
                $rest = str_replace($candidate, ' ', $rest);
            }
        }

        return ['paths' => array_slice(array_values(array_unique($paths)), 0, self::MAX_PATHS), 'rest' => $rest];
    }

    /**
     * The candidate without a leading "./" and without a final "/" or "." (the end of the sentence).
     */
    private static function clean(string $candidate): string
    {
        $candidate = rtrim($candidate, './');

        return str_starts_with($candidate, './') ? substr($candidate, 2) : $candidate;
    }

    /**
     * The repository's folder ("app/Http/") or files that match a candidate, with their real spelling.
     *
     * @return list<string>
     */
    private static function resolve(Repository $repository, string $candidate): array
    {
        if ($candidate === '') {
            return [];
        }

        $lower = Str::lower($candidate);
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $lower);

        $exactFile = $repository->files()->whereRaw('lower(path) = ?', [$lower])->value('path');

        if ($exactFile !== null) {
            return [$exactFile];
        }

        $fileInFolder = $repository->files()->whereRaw("lower(path) like ? escape '!'", [$escaped.'/%'])->value('path');

        if ($fileInFolder !== null) {
            return [substr($fileInFolder, 0, strlen($candidate) + 1)];
        }

        if (str_contains($candidate, '/')) {
            return [];
        }

        return $repository->files()
            ->whereRaw("lower(path) like ? escape '!'", ['%/'.$escaped])
            ->orderBy('path')
            ->limit(self::MAX_PATHS)
            ->pluck('path')
            ->all();
    }
}
