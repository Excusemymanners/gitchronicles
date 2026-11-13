<?php

namespace App\Diagrams;

use App\Analysis\LinkCatalog;
use App\Analysis\LinkScanner;
use App\Analysis\ModuleGrouper;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Everything the big picture ("How <name> works") and the flows are checked against, gathered once:
 * the application modules (with their layer, size, links in and out and the first sentence of their
 * documentation page), every connection between them with its kinds (imports and code_links), what each
 * module uses outside the code (programs, services, stores), and the places in the code that start the
 * work (routes, dispatched jobs, schedules, rendered pages, programs started). Plain arrays, so the
 * validators can be tested without a database.
 */
final class SystemFacts
{
    /**
     * Link kinds that show where work starts or is handed over, listed as entry points for the text generator.
     */
    private const ENTRY_KINDS = ['route', 'dispatch', 'schedule', 'event', 'render', 'runs', 'loads'];

    /**
     * At most this many places of each entry kind are listed.
     */
    private const ENTRIES_PER_KIND = 8;

    /**
     * Create the collector.
     */
    public function __construct(private CodeOverview $overview) {}

    /**
     * The facts of one repository.
     *
     * @return array{modules: array<string, array{id: int, layer: string, lines: int, files: int, in: int, out: int, sentence: string|null}>, links: list<array{from: string, to: string, weight: int, kinds: array<string, int>}>, externals: list<array{module: string, target: string, kind: string, count: int}>, targets: array<string, array{name: string, type: string, count: int}>, entries: list<array{kind: string, from: string, to: string, line: int, excerpt: string}>}
     */
    public function collect(Repository $repository): array
    {
        [$modules] = $this->overview->applicationModules($repository);

        // A project whose code is all in its root folder (a shell tool, a small library) is that one module.
        if ($modules->isEmpty()) {
            $modules = $repository->modules()->where('path', ModuleGrouper::ROOT_MODULE)
                ->whereHas('files', fn ($query) => $query->whereIn('language', CodeOverview::CODE_LANGUAGES))->get();
        }

        $paths = $modules->pluck('path', 'id');
        $links = array_map(fn (array $link): array => [
            'from' => $paths[$link['from']], 'to' => $paths[$link['to']], 'weight' => $link['weight'], 'kinds' => $link['kinds'],
        ], $this->overview->linksBetween($repository, $modules));
        $externals = array_map(fn (array $row): array => [...$row, 'module' => $paths[$row['module']]], $this->overview->externalLinks($repository, $modules));
        $sentences = ArchitectureReader::moduleSentences($repository, $paths->values()->all());
        $in = [];
        $out = [];

        foreach ($links as $link) {
            $out[$link['from']] = ($out[$link['from']] ?? 0) + $link['weight'];
            $in[$link['to']] = ($in[$link['to']] ?? 0) + $link['weight'];
        }

        $targets = [];

        foreach ($externals as $row) {
            $targets[$row['target']] ??= ['name' => LinkCatalog::name($row['target']), 'type' => self::targetType($row['target']), 'count' => 0];
            $targets[$row['target']]['count'] += $row['count'];
        }

        uasort($targets, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'modules' => $modules->mapWithKeys(fn (Module $module): array => [$module->path => [
                'id' => $module->id,
                'layer' => LayerClassifier::layerOf($module->path),
                'lines' => (int) $module->line_count,
                'files' => (int) $module->file_count,
                'in' => $in[$module->path] ?? 0,
                'out' => $out[$module->path] ?? 0,
                'sentence' => $sentences[$module->path] ?? null,
            ]])->all(),
            'links' => $links,
            'externals' => $externals,
            'targets' => $targets,
            'entries' => $this->entries($repository, $paths->keys()->all()),
        ];
    }

    /**
     * What a stored target is for a diagram: "tool", "external" or "data".
     */
    public static function targetType(string $target): string
    {
        return match (explode(':', $target, 2)[0]) {
            'tool' => 'tool',
            'store' => 'data',
            default => 'external',
        };
    }

    /**
     * The verb of a link kind ("dispatch" → "dispatches"); for a store, the verb of its kind of use.
     */
    public static function verb(string $kind): string
    {
        return LinkScanner::KINDS[$kind] ?? 'uses';
    }

    /**
     * The places in the code where work starts or is handed over: routes, dispatches, schedules, events,
     * rendered pages, programs and scripts started, page loaders — a few of each kind, test files left out.
     *
     * @param  list<int>  $moduleIds
     * @return list<array{kind: string, from: string, to: string, line: int, excerpt: string}>
     */
    private function entries(Repository $repository, array $moduleIds): array
    {
        $rows = DB::table('code_links')
            ->join('repository_files as source', 'source.id', '=', 'code_links.repository_file_id')
            ->leftJoin('repository_files as target', 'target.id', '=', 'code_links.target_file_id')
            ->where('code_links.repository_id', $repository->id)
            ->whereIn('code_links.kind', self::ENTRY_KINDS)
            ->whereIn('source.module_id', $moduleIds)
            ->where(fn ($query) => $query->whereNull('target.module_id')->orWhereColumn('target.module_id', '!=', 'source.module_id')->orWhere('code_links.kind', '!=', 'reference'))
            ->orderBy('code_links.kind')
            ->orderBy('source.path')
            ->orderBy('code_links.line')
            ->select('code_links.kind', 'source.path as from_path', 'target.path as to_path', 'code_links.target', 'code_links.line', 'code_links.excerpt')
            ->get();

        $entries = [];
        $perKind = [];

        foreach ($rows as $row) {
            $key = $row->kind.'|'.($row->to_path ?? $row->target);

            if (isset($entries[$key]) || ($perKind[$row->kind] ?? 0) >= self::ENTRIES_PER_KIND) {
                continue;
            }

            $perKind[$row->kind] = ($perKind[$row->kind] ?? 0) + 1;
            $entries[$key] = ['kind' => $row->kind, 'from' => $row->from_path, 'to' => $row->to_path ?? (string) $row->target, 'line' => (int) $row->line, 'excerpt' => $row->excerpt];
        }

        return array_values($entries);
    }
}
