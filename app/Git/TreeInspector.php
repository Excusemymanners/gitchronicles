<?php

namespace App\Git;

use App\Exceptions\RepositoryTooLarge;

/**
 * Counts the files and the bytes of a commit BEFORE they are written to disk.
 *
 * A blobless clone is small even when the commit it points to is huge: git stores identical content once,
 * so a tree may reference one blob thousands of times and the checkout would fill the disk. `git ls-tree -r -l`
 * lists every entry of the commit with its size (it needs no file content), so we can refuse the repository
 * first. The listing is read as a stream and git is stopped as soon as a limit is passed, so a hostile tree
 * with millions of entries never sits in memory.
 */
final class TreeInspector
{
    /**
     * @throws RepositoryTooLarge when the commit has more files or more bytes than the limits
     */
    public static function assertWithinLimits(string $clonePath, string $commit, int $maxFiles, int $maxBytes): void
    {
        $files = 0;
        $bytes = 0;
        $pending = '';

        $process = Git::process($clonePath)->timeout(120)->start(
            ['git', 'ls-tree', '-r', '-l', '-z', $commit, '--'],
            function (string $type, string $buffer) use (&$files, &$bytes, &$pending): void {
                if ($type !== 'out') {
                    return;
                }

                $pending .= $buffer;
                $records = explode("\0", $pending);
                $pending = array_pop($records);

                foreach ($records as $record) {
                    $files++;

                    // "100644 blob <sha> <size>\t<path>": the size is "-" for a submodule.
                    if (preg_match('/\A\d+ \w+ \w+\s+(\d+)\t/', $record, $match) === 1) {
                        $bytes += (int) $match[1];
                    }
                }
            },
        );

        while ($process->running()) {
            if ($files > $maxFiles || $bytes > $maxBytes) {
                $process->stop();

                break;
            }

            usleep(10_000);
        }

        $process->wait();

        if ($files > $maxFiles) {
            throw RepositoryTooLarge::because('too_many_files', ['max' => number_format($maxFiles)]);
        }

        if ($bytes > $maxBytes) {
            throw RepositoryTooLarge::because('too_large', ['max' => intdiv($maxBytes, 1024 * 1024)]);
        }
    }
}
