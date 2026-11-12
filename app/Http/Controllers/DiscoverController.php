<?php

namespace App\Http\Controllers;

use App\Discover\Leaderboard;
use App\Discover\LeaderboardEntry;
use App\Enums\DiscoverPeriod;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public „Descoperă” page (bonus): the repositories that gain stars the fastest, from the daily
 * snapshots of `discover:refresh` (see App\Discover\Leaderboard for how the ranking is computed).
 *
 * The filters live in the query string (?period=7d&language=Rust&page=2), so a filtered ranking can be shared.
 * Each row can be added to GitChronicle with the home page's form (POST /repositories, the AddRepository flow),
 * or opened directly when GitChronicle already documents it.
 */
class DiscoverController extends Controller
{
    /**
     * Repositories on one page of the ranking.
     */
    public const PER_PAGE = 25;

    /**
     * Show one page of the ranking. Unknown filter values fall back to the defaults instead of an error,
     * because the URL may be shared or typed by hand.
     */
    public function __invoke(Request $request, Leaderboard $leaderboard): Response
    {
        $period = DiscoverPeriod::tryFrom($request->string('period')->toString()) ?? DiscoverPeriod::default();
        $language = Str::limit(trim($request->string('language')->toString()), 100, '') ?: null;

        $ranking = $leaderboard->rank($period, $language);
        $page = max(1, $request->integer('page', 1));

        $paginator = new LengthAwarePaginator(
            $ranking->entries->forPage($page, self::PER_PAGE)->values(),
            $ranking->entries->count(),
            self::PER_PAGE,
            $page,
            ['path' => '/discover', 'query' => array_filter(['period' => $period->value, 'language' => $language])],
        );

        $repositoryOfTheDay = $leaderboard->repositoryOfTheDay();
        $documented = $this->documentedRepositories([...$paginator->items(), ...array_filter([$repositoryOfTheDay])]);

        return Inertia::render('discover', [
            'filters' => ['period' => $period->value, 'language' => $language],
            'default_period' => DiscoverPeriod::default()->value,
            // Only the values ('24h', '7d', '30d'): the page writes their names in the visitor's language.
            'periods' => array_column(DiscoverPeriod::cases(), 'value'),
            'languages' => $leaderboard->languages(),
            'ranking' => [
                'day' => $ranking->day,
                'baseline_day' => $ranking->baselineDay,
                'first_day' => $leaderboard->firstDay(),
                'ordered_by' => $ranking->orderedByGrowth ? 'growth' : 'average',
                'total' => $paginator->total(),
            ],
            'entries' => collect($paginator->items())
                ->map(fn (LeaderboardEntry $entry, int $index): array => $this->entry($entry, $paginator->firstItem() + $index, $documented))
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'previous_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
            ],
            'repository_of_the_day' => $repositoryOfTheDay === null ? null : $this->entry($repositoryOfTheDay, 1, $documented),
        ]);
    }

    /**
     * The GitChronicle repositories among these entries, as canonical URL => id. A documented repository
     * is stored with its canonical, lowercase URL, so this is a case-insensitive match of owner/name.
     *
     * @param  list<LeaderboardEntry>  $entries
     * @return array<string, int>
     */
    private function documentedRepositories(array $entries): array
    {
        $urls = array_map(fn (LeaderboardEntry $entry): string => $entry->repository->canonicalUrl(), $entries);

        return Repository::whereIn('url', $urls)->pluck('id', 'url')->all();
    }

    /**
     * One row of the page.
     *
     * @param  array<string, int>  $documented
     * @return array<string, mixed>
     */
    private function entry(LeaderboardEntry $entry, int $position, array $documented): array
    {
        $repository = $entry->repository;
        $documentedId = $documented[$repository->canonicalUrl()] ?? null;

        return [
            'position' => $position,
            'id' => $repository->id,
            'full_name' => $repository->full_name,
            'github_url' => $repository->githubUrl(),
            'description' => $repository->description,
            'language' => $repository->language,
            'stars' => $entry->stars,
            'growth' => $entry->growth,
            'average_per_day' => round($entry->averagePerDay, 1),
            'age_days' => $entry->ageDays,
            // „Documentează” posts this link to POST /repositories, like the form on the home page.
            'add_url' => $repository->canonicalUrl(),
            'documentation_url' => $documentedId === null ? null : route('repositories.show', $documentedId, absolute: false),
        ];
    }
}
