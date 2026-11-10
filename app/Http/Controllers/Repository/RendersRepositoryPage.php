<?php

namespace App\Http\Controllers\Repository;

use App\Http\Resources\RepositoryResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every page of a repository (the tabs „Prezentare”, „Documentație”, „Diagrame”, „Asistent”, „Istoric”,
 * „Video”) shares the same header (RepositoryLayout in React), which needs the same three props:
 *
 * - "repository": the repository itself (RepositoryResource = the RepositoryItem type in TypeScript);
 * - "can_delete": only the user who added it sees „Șterge” (RepositoryPolicy::delete);
 * - "can_retry": any logged-in user sees „Reîncearcă” after a failure (RepositoryPolicy::retry).
 *
 * This trait builds them in one place, so every tab controller sends exactly the same shape.
 */
trait RendersRepositoryPage
{
    /**
     * Render an Inertia page of the repository with the header props and the page's own props.
     *
     * @param  array<string, mixed>  $props
     */
    protected function renderRepositoryPage(Request $request, Repository $repository, string $component, array $props = []): Response
    {
        return Inertia::render($component, [
            'repository' => RepositoryResource::make($repository)->resolve(),
            'can_delete' => $request->user()?->can('delete', $repository) ?? false,
            'can_retry' => $request->user()?->can('retry', $repository) ?? false,
            ...$props,
        ]);
    }
}
