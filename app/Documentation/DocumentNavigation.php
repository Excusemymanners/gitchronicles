<?php

namespace App\Documentation;

use App\Models\Document;
use App\Models\Repository;

/**
 * The chapter list of a repository's documentation, in one place, for every screen that shows it
 * (the menu of the „Documentație” tab, and the left column of the repository workspace):
 *
 * 1. the overview ("Overview"), 2. the generated README ("Generated README"), 3. the module pages, grouped by
 * their top folder ("app/Http" and "app/Models" are in the group "app"; the root folder's page has no group).
 */
final class DocumentNavigation
{
    /**
     * Every page of the repository's documentation, in menu order.
     *
     * @return list<array{slug: string, title: string, kind: string, group: string|null, is_stale: bool, href: string}>
     */
    public static function for(Repository $repository): array
    {
        return Document::whereBelongsTo($repository)
            ->get(['id', 'repository_id', 'slug', 'title', 'is_stale'])
            ->map(fn (Document $document): array => self::item($document, $repository))
            ->sortBy(fn (array $item): array => [
                match ($item['kind']) {
                    'overview' => 0,
                    'readme' => 1,
                    default => 2,
                },
                $item['group'] ?? '',
                $item['title'],
            ])
            ->values()
            ->all();
    }

    /**
     * One chapter: how the menu shows it and where it leads.
     *
     * - kind: "overview", "readme" or "module";
     * - title: the module's path for a module page (TemplateDocumentationWriter::ROOT_MODULE_TITLE for "/");
     * - group: the top folder of a module page, null for the other pages and the root folder.
     *
     * @return array{slug: string, title: string, kind: string, group: string|null, is_stale: bool, href: string}
     */
    public static function item(Document $document, Repository $repository): array
    {
        $kind = match ($document->slug) {
            Document::OVERVIEW => 'overview',
            Document::README => 'readme',
            default => 'module',
        };

        $isRootModule = $kind === 'module' && $document->title === '/';

        return [
            'slug' => $document->slug,
            'title' => $isRootModule ? TemplateDocumentationWriter::ROOT_MODULE_TITLE : $document->title,
            'kind' => $kind,
            'group' => $kind === 'module' && ! $isRootModule ? explode('/', $document->title)[0] : null,
            'is_stale' => $document->is_stale,
            // The overview is the tab's own page (/docs), so the workspace and the menu point to one address.
            'href' => $kind === 'overview'
                ? route('repositories.docs', $repository, absolute: false)
                : route('repositories.docs', ['repository' => $repository, 'slug' => $document->slug], absolute: false),
        ];
    }
}
