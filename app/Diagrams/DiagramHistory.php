<?php

namespace App\Diagrams;

use App\Models\Diagram;
use App\Models\DiagramVersion;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * The history of a repository's diagrams, for the time travel (Faza 9). The diagrams table always
 * holds only the current diagrams; after every BuildDiagrams step, record() compares them with the
 * newest saved version of each one and adds a DiagramVersion only where something changed:
 *
 * - a new diagram, or one whose Mermaid code is different: a version with the new code;
 * - a diagram that no longer exists (a view left without data, a flow replaced by another):
 *   a version without code, which says "gone since this commit".
 *
 * A project whose architecture did not change between two commits adds no rows at all.
 */
final class DiagramHistory
{
    /**
     * Save the changes of the current diagrams as new versions, at the repository's current commit,
     * remembering the run that drew them. Returns how many versions were added.
     */
    public function record(Repository $repository, ProcessingRun $run): int
    {
        $current = $repository->diagrams()->get()->keyBy(fn (Diagram $diagram): string => self::identity($diagram));
        $newest = $this->newestVersions($repository);
        $added = 0;

        foreach ($current as $identity => $diagram) {
            if ($newest->get($identity)?->mermaid !== $diagram->mermaid) {
                $this->addVersion($repository, $run, $diagram->type->value, $diagram->key, $diagram->mermaid, $diagram->meta);
                $added++;
            }
        }

        foreach ($newest->diffKeys($current) as $version) {
            if ($version->mermaid !== null) {
                $this->addVersion($repository, $run, $version->type->value, $version->key, null, null);
                $added++;
            }
        }

        return $added;
    }

    /**
     * The diagrams as they were right after a run (the run of a processed commit, see ProcessedCommits):
     * for every diagram, the newest version drawn by that run or an older one. A diagram that was gone
     * by then is left out.
     *
     * @return Collection<int, DiagramVersion>
     */
    public function at(Repository $repository, ProcessingRun $run): Collection
    {
        return $repository->diagramVersions()
            ->where('processing_run_id', '<=', $run->id)
            ->get()
            ->keyBy(fn (DiagramVersion $version): string => self::identity($version))
            ->filter(fn (DiagramVersion $version): bool => $version->mermaid !== null)
            ->values();
    }

    /**
     * The newest version of every diagram the repository ever had, by identity.
     *
     * @return Collection<string, DiagramVersion>
     */
    private function newestVersions(Repository $repository): Collection
    {
        // keyBy keeps the last row of each identity, and the versions come oldest first.
        return $repository->diagramVersions()->get()->keyBy(fn (DiagramVersion $version): string => self::identity($version));
    }

    /**
     * Add one version at the repository's current commit; without code it marks a diagram that is gone.
     *
     * @param  array<string, mixed>|null  $meta
     */
    private function addVersion(Repository $repository, ProcessingRun $run, string $type, string $key, ?string $mermaid, ?array $meta): void
    {
        $repository->diagramVersions()->create([
            'processing_run_id' => $run->id,
            'type' => $type,
            'key' => $key,
            'sha' => $repository->last_sha,
            'mermaid' => $mermaid,
            'meta' => $meta,
        ]);
    }

    /**
     * What makes a diagram the same diagram over time: its type and its key.
     */
    private static function identity(Diagram|DiagramVersion $diagram): string
    {
        return "{$diagram->type->value}|{$diagram->key}";
    }
}
