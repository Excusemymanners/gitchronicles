<?php

namespace App\Http\Controllers\Repository;

use App\Diagrams\DiagramHistory;
use App\Enums\DiagramType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiagramResource;
use App\Incremental\ProcessedCommits;
use App\Models\Diagram;
use App\Models\DiagramVersion;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Response;

/**
 * The „Diagrame” tab of a repository (Faza 7): the „Cum funcționează” flows written by Claude,
 * the layers view and the C4 views (containers, components, context), drawn by BuildDiagrams.
 *
 * With "?sha=" of a processed commit the page travels in time (Faza 9): the diagrams are the ones
 * drawn up to that commit's run (DiagramHistory), and "time_travel.changes" says what is different now:
 * which views were drawn again since, and which modules appeared or disappeared.
 */
class DiagramsController extends Controller
{
    use RendersRepositoryPage;
    use TravelsInTime;

    /**
     * How many module paths each list of the comparison sends at most (the rest are counted).
     */
    public const MAX_LISTED_MODULES = 40;

    /**
     * Show the tab to anyone: repositories are public.
     *
     * "diagrams" and "time_travel" are closures, so the polling of the page header (only "repository"
     * and "can_retry") does not query the diagrams every 2 seconds.
     */
    public function __invoke(Request $request, Repository $repository, ProcessedCommits $commits, DiagramHistory $history): Response
    {
        $chosenRun = $this->chosenRun($request, $repository, $commits);

        $diagrams = fn (): Collection => $chosenRun === null
            ? $repository->diagrams()->orderBy('id')->get()
            : $this->diagramsAt($repository, $history->at($repository, $chosenRun));

        return $this->renderRepositoryPage($request, $repository, 'repositories/diagrams', [
            'diagrams' => fn (): array => $this->grouped($diagrams()),
            'time_travel' => fn (): array => [
                'commits' => $commits->of($repository),
                'selected' => $chosenRun?->to_sha,
                'changes' => $chosenRun === null ? null : $this->changesSince($repository, $chosenRun, $diagrams()),
            ],
        ]);
    }

    /**
     * The diagrams grouped for the page: the flows in the order Claude wrote them,
     * then one entry per architecture view (null when that view has no data).
     *
     * @param  Collection<int, Diagram>  $diagrams
     * @return array{overview: array<string, mixed>|null, flows: list<array<string, mixed>>, layers: array<string, mixed>|null, container: array<string, mixed>|null, component: array<string, mixed>|null, context: array<string, mixed>|null}
     */
    private function grouped(Collection $diagrams): array
    {
        $view = function (DiagramType $type) use ($diagrams): ?array {
            $diagram = $diagrams->first(fn (Diagram $diagram): bool => $diagram->type === $type);

            return $diagram === null ? null : DiagramResource::make($diagram)->resolve();
        };

        return [
            'overview' => $view(DiagramType::Overview),
            'flows' => DiagramResource::collection($diagrams->where('type', DiagramType::Flow)->values())->resolve(),
            'layers' => $view(DiagramType::Layers),
            'container' => $view(DiagramType::Container),
            'component' => $view(DiagramType::Component),
            'context' => $view(DiagramType::Context),
        ];
    }

    /**
     * Old versions as diagrams the page can draw: the same fields, with the version's commit as
     * source_sha, so a flow's links point to the code as it was then. They are never saved.
     *
     * @param  Collection<int, DiagramVersion>  $versions
     * @return Collection<int, Diagram>
     */
    private function diagramsAt(Repository $repository, Collection $versions): Collection
    {
        return $versions->map(fn (DiagramVersion $version): Diagram => (new Diagram([
            'type' => $version->type,
            'key' => $version->key,
            'mermaid' => $version->mermaid,
            'meta' => $version->meta,
            'source_sha' => $version->sha,
        ]))->setRelation('repository', $repository));
    }

    /**
     * What is different now compared with the chosen commit, from real data only:
     *
     * - "diagrams": the titles of the views whose code is not the same now (drawn again since, or gone);
     * - "modules_added" / "modules_removed": the modules that exist now but did not then, and the other
     *   way round. The two lists of modules come from the code analysis: the one saved by the run that
     *   processed the chosen commit (module_paths) and the current modules table. The diagrams themselves
     *   could not be compared instead: each one draws only the biggest modules. Null when the run of
     *   that commit did not save its list.
     *
     * @param  ProcessingRun  $run  the run of the chosen commit
     * @param  Collection<int, Diagram>  $diagramsThen
     * @return array{diagrams: list<string>, modules_added: list<string>|null, modules_removed: list<string>|null, modules_added_count: int|null, modules_removed_count: int|null}
     */
    private function changesSince(Repository $repository, ProcessingRun $run, Collection $diagramsThen): array
    {
        $identity = fn (Diagram $diagram): string => "{$diagram->type->value}|{$diagram->key}";
        $now = $repository->diagrams()->get()->keyBy($identity);

        $changedDiagrams = $diagramsThen
            ->filter(fn (Diagram $diagram): bool => $now->get($identity($diagram))?->mermaid !== $diagram->mermaid)
            ->map(fn (Diagram $diagram): string => $diagram->meta['title'] ?? $diagram->type->value)
            ->values()
            ->all();

        $modulesThen = $run->module_paths;
        $modulesNow = $repository->modules()->orderBy('path')->pluck('path')->all();
        $added = $modulesThen === null ? null : array_values(array_diff($modulesNow, $modulesThen));
        $removed = $modulesThen === null ? null : array_values(array_diff($modulesThen, $modulesNow));

        return [
            'diagrams' => $changedDiagrams,
            'modules_added' => $added === null ? null : array_slice($added, 0, self::MAX_LISTED_MODULES),
            'modules_removed' => $removed === null ? null : array_slice($removed, 0, self::MAX_LISTED_MODULES),
            'modules_added_count' => $added === null ? null : count($added),
            'modules_removed_count' => $removed === null ? null : count($removed),
        ];
    }
}
