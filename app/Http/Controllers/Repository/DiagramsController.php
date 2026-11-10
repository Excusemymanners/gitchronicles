<?php

namespace App\Http\Controllers\Repository;

use App\Enums\DiagramType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DiagramResource;
use App\Models\Diagram;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The „Diagrame” tab of a repository (Faza 7): the „Cum funcționează” flows written by Claude,
 * the layers view and the C4 views (containers, components, context), drawn by BuildDiagrams.
 */
class DiagramsController extends Controller
{
    use RendersRepositoryPage;

    /**
     * Show the tab to anyone: repositories are public.
     *
     * "diagrams" is a closure, so the polling of the page header (only "repository" and "can_retry")
     * does not query the diagrams every 2 seconds.
     */
    public function __invoke(Request $request, Repository $repository): Response
    {
        return $this->renderRepositoryPage($request, $repository, 'repositories/diagrams', [
            'diagrams' => fn (): array => $this->diagramsOf($repository),
        ]);
    }

    /**
     * The repository's diagrams grouped for the page: the flows in the order Claude wrote them,
     * then one entry per architecture view (null when that view has no data).
     *
     * @return array{flows: list<array<string, mixed>>, layers: array<string, mixed>|null, container: array<string, mixed>|null, component: array<string, mixed>|null, context: array<string, mixed>|null}
     */
    private function diagramsOf(Repository $repository): array
    {
        $diagrams = $repository->diagrams()->orderBy('id')->get();

        $view = function (DiagramType $type) use ($diagrams): ?array {
            $diagram = $diagrams->first(fn (Diagram $diagram): bool => $diagram->type === $type);

            return $diagram === null ? null : DiagramResource::make($diagram)->resolve();
        };

        return [
            'flows' => DiagramResource::collection($diagrams->where('type', DiagramType::Flow)->values())->resolve(),
            'layers' => $view(DiagramType::Layers),
            'container' => $view(DiagramType::Container),
            'component' => $view(DiagramType::Component),
            'context' => $view(DiagramType::Context),
        ];
    }
}
