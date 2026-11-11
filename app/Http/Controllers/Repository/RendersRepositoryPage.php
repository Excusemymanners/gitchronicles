<?php

namespace App\Http\Controllers\Repository;

use App\Enums\ProcessingRunStatus;
use App\Http\Resources\RepositoryResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every page of a repository („Prezentare”, „Documentație”, „Diagrame”, „Asistent”, „Istoric”, „Video”)
 * opens inside the same workspace (WorkspaceLayout in React), which needs the same props:
 *
 * - "repository": the repository itself (RepositoryResource = the RepositoryItem type in TypeScript);
 * - "can_delete": only the user who added it sees „Șterge” (RepositoryPolicy::delete);
 * - "can_retry": any logged-in user sees „Reîncearcă” after a failure (RepositoryPolicy::retry);
 * - "can_check_updates": any logged-in user sees „Verifică actualizări” on a ready repository
 *   (RepositoryPolicy::checkUpdates);
 * - "last_update": the commit and the time of the newest run that ended well, for „Actualizat la
 *   commitul … · acum 5 min” in the top bar (Faza 9); null until the first one;
 * - "workspace": the chapters on the left, the „Elemente” cards on the right and the assistant's
 *   starter questions (WorkspaceResource). It is a closure, so Inertia builds it only when the page
 *   asks for it: the status poll every 2 seconds asks for "repository" and "can_retry" only.
 *
 * This trait builds them in one place, so every page controller sends exactly the same shape.
 */
trait RendersRepositoryPage
{
    /**
     * Render an Inertia page of the repository with the workspace props and the page's own props.
     *
     * @param  array<string, mixed>  $props
     */
    protected function renderRepositoryPage(Request $request, Repository $repository, string $component, array $props = []): Response
    {
        return Inertia::render($component, [
            'repository' => RepositoryResource::make($repository)->resolve(),
            'can_delete' => $request->user()?->can('delete', $repository) ?? false,
            'can_retry' => $request->user()?->can('retry', $repository) ?? false,
            'can_check_updates' => $request->user()?->can('checkUpdates', $repository) ?? false,
            'last_update' => fn () => $this->lastUpdateOf($repository),
            'workspace' => fn () => WorkspaceResource::make($repository)->resolve(),
            ...$props,
        ]);
    }

    /**
     * When the repository was last brought up to date: the newest run that succeeded, or that found
     * nothing new (skipped), with the commit it reached.
     *
     * @return array{sha: string, finished_at: string, nothing_new: bool}|null
     */
    private function lastUpdateOf(Repository $repository): ?array
    {
        $run = $repository->processingRuns()
            ->whereIn('status', [ProcessingRunStatus::Succeeded, ProcessingRunStatus::Skipped])
            ->whereNotNull('to_sha')
            ->whereNotNull('finished_at')
            ->latest('id')
            ->first();

        return $run === null ? null : [
            'sha' => $run->to_sha,
            'finished_at' => $run->finished_at->toIso8601String(),
            'nothing_new' => $run->status === ProcessingRunStatus::Skipped,
        ];
    }
}
