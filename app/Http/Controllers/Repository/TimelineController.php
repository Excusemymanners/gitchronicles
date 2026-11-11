<?php

namespace App\Http\Controllers\Repository;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Git\HistoryStatistics;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommitResource;
use App\Http\Resources\ProcessingRunResource;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The „Istoric” tab of a repository: the imported commits (newest first, grouped by month on the page),
 * the GitChronicle processing runs as markers on the commits they processed, the „Zone fierbinți”
 * and the contributors. Each run shows how it worked: its kind, what started it, what changed and what it rewrote.
 *
 * Every prop is a closure, so the layout's polling (which asks only for "repository" and
 * "can_retry") runs none of these queries.
 */
class TimelineController extends Controller
{
    use RendersRepositoryPage;

    /**
     * How many commits one page of the list shows; „Încarcă mai multe” adds the next page.
     */
    public const COMMITS_PER_PAGE = 30;

    /**
     * How many runs are placed on the commits: the newest ones.
     */
    public const SHOWN_RUNS = 100;

    /**
     * How many files the „Zone fierbinți” list shows.
     */
    public const SHOWN_HOTSPOTS = 10;

    /**
     * How many people the „Contribuitori” list shows (the summary gives the total).
     */
    public const SHOWN_CONTRIBUTORS = 10;

    /**
     * Show the tab to anyone: repositories are public.
     *
     * "commits" is an Inertia scroll prop: a paginated list whose next pages are added to the
     * ones already shown. "q" searches the subject and the author name.
     */
    public function __invoke(Request $request, Repository $repository, HistoryStatistics $statistics): Response
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['q'] ?? '');

        return $this->renderRepositoryPage($request, $repository, 'repositories/timeline', [
            'summary' => fn () => $statistics->summary($repository),

            'commits' => Inertia::scroll(fn () => CommitResource::collection(
                $repository->commits()
                    ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                        ->whereLike('subject', "%{$search}%")
                        ->orWhereLike('author_name', "%{$search}%")))
                    ->orderByDesc('authored_at')
                    ->orderByDesc('id')
                    ->simplePaginate(self::COMMITS_PER_PAGE)
                    ->withQueryString(),
            )),

            'runs' => fn () => ProcessingRunResource::collection($this->runsOf($repository))->resolve(),

            'hotspots' => fn () => $statistics->hotspots($repository, self::SHOWN_HOTSPOTS),

            'contributors' => fn () => $statistics->contributors($repository, self::SHOWN_CONTRIBUTORS),

            'filters' => ['q' => $search],
        ]);
    }

    /**
     * The newest runs that reached a commit. Every incremental run that succeeded gets, as its
     * "reference" relation, the last complete processing (first or full) that succeeded before it:
     * the page compares the two to show what the incremental update saved (Faza 9).
     *
     * @return Collection<int, ProcessingRun>
     */
    private function runsOf(Repository $repository): Collection
    {
        $runs = $repository->processingRuns()->whereNotNull('to_sha')->latest('id')->limit(self::SHOWN_RUNS)->get();

        $completeRuns = $repository->processingRuns()
            ->whereIn('mode', [ProcessingMode::Initial, ProcessingMode::Full])
            ->where('status', ProcessingRunStatus::Succeeded)
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            if ($run->isIncremental() && $run->status === ProcessingRunStatus::Succeeded) {
                $run->setRelation('reference', $completeRuns->last(fn (ProcessingRun $complete): bool => $complete->id < $run->id));
            }
        }

        return $runs;
    }
}
