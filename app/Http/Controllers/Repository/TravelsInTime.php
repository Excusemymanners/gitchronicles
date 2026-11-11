<?php

namespace App\Http\Controllers\Repository;

use App\Incremental\ProcessedCommits;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Http\Request;

/**
 * The time travel of the „Documentație” and „Diagrame” pages (Faza 9): the "?sha=" of the address
 * chooses a processed commit, and the page then shows the repository as it was at that commit.
 * Without it, or with the newest processed commit, the page shows the current state.
 */
trait TravelsInTime
{
    /**
     * The run of the processed commit chosen in the address (see ProcessedCommits::runAt()), or null for
     * the current state. A sha that is not a commit this repository was processed at gives 404.
     */
    protected function chosenRun(Request $request, Repository $repository, ProcessedCommits $commits): ?ProcessingRun
    {
        $sha = $request->query('sha');

        if ($sha === null || $sha === '') {
            return null;
        }

        $run = is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/', $sha) === 1 ? $commits->runAt($repository, $sha) : null;

        if ($run === null) {
            abort(404);
        }

        return $sha === $commits->current($repository) ? null : $run;
    }
}
