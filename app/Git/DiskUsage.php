<?php

namespace App\Git;

use Illuminate\Support\Facades\Process;

/**
 * How many bytes a folder takes on disk, from `du` (the same number the operating system counts against a quota).
 */
final class DiskUsage
{
    /**
     * The bytes used by a folder and everything in it; 0 when the folder does not exist.
     */
    public static function of(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        // -s: one total, -k: in kilobytes, --: the folder is never read as an option; links are not followed.
        $output = Process::timeout(30)->run(['du', '-sk', '--', $path])->output();

        return (int) $output * 1024;
    }
}
