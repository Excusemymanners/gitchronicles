<?php

namespace App\Media;

use App\Documentation\CloneFiles;
use App\Documentation\ProjectFacts;
use App\Documentation\SourceLinks;
use App\Enums\DiagramType;
use App\Git\HistoryStatistics;
use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Support\Str;

/**
 * Gathers the real facts of a repository for its video and slides (PresentationFacts):
 *
 * - the README of the clone (read safely with CloneFiles) and the generated „Despre proiect” page,
 *   both cut to a budget, without image and badge lines that say nothing;
 * - GitHub's stars, language, license and topics (GitHubMetadata; null without network);
 * - the languages, modules and sizes of the code analysis, the git history (HistoryStatistics);
 * - the first command of a README code block, the commands of the manifests (ProjectFacts);
 * - the technologies and the Mermaid code of the „Containere” (else „Straturi”) diagram.
 *
 * Nothing is estimated: what is missing stays null or empty.
 */
final class FactsCollector
{
    /**
     * How much of the README the AI receives, in characters.
     */
    public const README_CHARACTERS = 12000;

    /**
     * How much of the „Despre proiect” page the AI receives, in characters.
     */
    public const OVERVIEW_CHARACTERS = 6000;

    /**
     * How many modules the slides list.
     */
    public const MAX_MODULES = 6;

    /**
     * How many languages the slides list.
     */
    public const MAX_LANGUAGES = 5;

    /**
     * Modules inside these folders are not listed on the slides (they are not the application itself).
     */
    private const SKIPPED_MODULE_FOLDERS = ['tests', 'test', '__tests__', 'spec', 'docs', 'doc', 'config'];

    /**
     * The README file names looked for in the root folder, in this order.
     */
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md', 'README.markdown', 'README.rst', 'README.txt', 'README'];

    /**
     * The first word of a line that is a shell command (for „Cum începi”). Downloads piped into a shell
     * (curl, wget) are left out on purpose: a video should not tell people to run them.
     */
    private const COMMAND_WORDS = [
        'git', 'npm', 'npx', 'pnpm', 'yarn', 'bun', 'deno', 'composer', 'php', 'pip', 'pip3', 'pipx', 'python',
        'python3', 'uv', 'uvx', 'poetry', 'cargo', 'go', 'docker', 'make', 'brew', 'gem', 'bundle', 'dotnet', 'mvn',
        'gradle', 'rustup', 'cmake', 'flutter', 'dart', 'mix', 'swift', 'conda', 'helm', 'kubectl',
    ];

    /**
     * The longest command shown on the screen.
     */
    private const MAX_COMMAND_LENGTH = 60;

    /**
     * Create the collector.
     */
    public function __construct(
        private GitHubMetadata $gitHub,
        private HistoryStatistics $history,
    ) {}

    /**
     * Gather the facts of a repository.
     */
    public function collect(Repository $repository): PresentationFacts
    {
        $links = SourceLinks::for($repository);
        $clone = new CloneFiles($repository);
        $github = $this->gitHub->for($repository);
        $paths = $repository->files()->pluck('path')->all();
        $projectFacts = new ProjectFacts($clone, $paths);

        [$readmePath, $readme] = $this->readme($clone);
        $overview = Document::whereBelongsTo($repository)->where('slug', Document::OVERVIEW)->value('markdown');

        $sources = [];

        if ($readme !== null) {
            $sources['README.md'] = ['text' => $readme, 'url' => $links->file($readmePath)];
        }

        if (is_string($overview) && trim($overview) !== '') {
            $sources['Despre proiect'] = [
                'text' => Str::limit(trim($overview), self::OVERVIEW_CHARACTERS, '…', preserveWords: true),
                'url' => route('repositories.docs', $repository, absolute: false),
            ];
        }

        if ($github !== null && $this->gitHubLines($github) !== '') {
            $sources['GitHub'] = ['text' => $this->gitHubLines($github), 'url' => $repository->url];
        }

        return new PresentationFacts(
            owner: $repository->owner,
            name: $repository->name,
            url: "https://github.com/{$repository->owner}/{$repository->name}",
            sha: $repository->last_sha,
            description: RomanianText::clean($github['description'] ?? $repository->description ?? '') ?: null,
            sources: $sources,
            github: $github,
            // GitHub recognizes the LICENSE file; without its answer, the license a manifest declares.
            license: $github !== null ? $github['license'] : ($projectFacts->license['name'] ?? null),
            languages: $this->languages($repository),
            modules: $this->modules($repository),
            stats: $this->stats($repository),
            history: $this->historyFacts($repository),
            startCommand: $readme === null ? null : $this->startCommand($readme, $links->file($readmePath)),
            commands: array_map(fn (array $command): array => ['command' => $command['command'], 'source' => $command['source']], $projectFacts->commands),
            technologies: $this->technologies($repository),
            diagram: $this->diagram($repository),
        );
    }

    /**
     * The README of the root folder (path and text), without the lines that are only images, badges or
     * HTML, cut to README_CHARACTERS. [null, null] when there is none.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function readme(CloneFiles $clone): array
    {
        foreach (self::README_NAMES as $name) {
            $text = $clone->read($name);

            if ($text === null) {
                continue;
            }

            $lines = array_filter(
                preg_split('/\R/', RomanianText::commaBelow($text)) ?: [],
                fn (string $line): bool => preg_match('/\A\s*(!\[|\[!\[|<img|<p\b|<\/p>|<a\b|<\/a>|<br|<!--)/i', $line) !== 1,
            );
            $text = trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');

            return $text === '' ? [null, null] : [$name, Str::limit($text, self::README_CHARACTERS, '…', preserveWords: true)];
        }

        return [null, null];
    }

    /**
     * GitHub's answer as short lines of text, so the AI can quote it like any other source.
     *
     * @param  array{stars: int, forks: int, language: string|null, license: string|null, topics: list<string>, homepage: string|null, description: string|null}  $github
     */
    private function gitHubLines(array $github): string
    {
        return implode("\n", array_filter([
            $github['description'] === null ? null : "Descriere: {$github['description']}",
            "Stele: {$github['stars']}",
            $github['language'] === null ? null : "Limbaj principal: {$github['language']}",
            $github['license'] === null ? null : "Licență: {$github['license']}",
            $github['topics'] === [] ? null : 'Subiecte: '.implode(', ', $github['topics']),
            $github['homepage'] === null ? null : "Site: {$github['homepage']}",
        ]));
    }

    /**
     * The first shell command found in a code block of the README, copied exactly (without a leading "$ "
     * prompt or a trailing "# comment"), with the link to the README. Null when the README shows none.
     *
     * @return array{command: string, url: string|null}|null
     */
    private function startCommand(string $readme, string $readmeUrl): ?array
    {
        preg_match_all('/^[ \t]*(```|~~~)[^\n]*\n(.*?)^[ \t]*\1/ms', $readme, $blocks);

        foreach ($blocks[2] as $block) {
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                $command = trim(preg_replace('/\s+#.*\z/', '', preg_replace('/\A\s*[$>]\s+/', '', $line) ?? '') ?? '');
                $firstWord = strtolower(strtok($command, ' ') ?: '');

                if (in_array($firstWord, self::COMMAND_WORDS, true) && mb_strlen($command) <= self::MAX_COMMAND_LENGTH) {
                    return ['command' => $command, 'url' => $readmeUrl];
                }
            }
        }

        return null;
    }

    /**
     * The languages of the analyzed files, most lines first, with their share of the lines.
     *
     * @return list<array{name: string, files: int, lines: int, percent: int}>
     */
    private function languages(Repository $repository): array
    {
        $languages = $repository->files()
            ->toBase()
            ->whereNotNull('language')
            ->selectRaw('language, count(*) as file_count, sum(lines) as line_count')
            ->groupBy('language')
            ->orderByDesc('line_count')
            ->orderBy('language')
            ->get();

        $totalLines = max(1, (int) $languages->sum('line_count'));

        return $languages->take(self::MAX_LANGUAGES)->map(fn (object $language): array => [
            'name' => (string) $language->language,
            'files' => (int) $language->file_count,
            'lines' => (int) $language->line_count,
            'percent' => (int) round(100 * (int) $language->line_count / $totalLines),
        ])->values()->all();
    }

    /**
     * The biggest modules (by lines), without the root folder and the folders that are not the
     * application itself: tests, documentation and configuration.
     *
     * @return list<array{path: string, files: int, lines: int}>
     */
    private function modules(Repository $repository): array
    {
        return $repository->modules()
            ->orderByDesc('line_count')
            ->orderBy('path')
            ->get(['path', 'file_count', 'line_count'])
            ->reject(fn (Module $module): bool => $module->path === '/'
                || array_intersect(explode('/', strtolower($module->path)), self::SKIPPED_MODULE_FOLDERS) !== [])
            ->take(self::MAX_MODULES)
            ->map(fn (Module $module): array => ['path' => $module->path, 'files' => $module->file_count, 'lines' => $module->line_count])
            ->values()
            ->all();
    }

    /**
     * The sizes found by the code analysis.
     *
     * @return array{files: int, lines: int, modules: int}
     */
    private function stats(Repository $repository): array
    {
        $totals = $repository->files()->toBase()->selectRaw('count(*) as file_count, sum(lines) as line_count')->first();

        return [
            'files' => (int) ($totals->file_count ?? 0),
            'lines' => (int) ($totals->line_count ?? 0),
            'modules' => $repository->modules()->count(),
        ];
    }

    /**
     * The key numbers of the imported history and the three people with the most commits.
     *
     * @return array{commit_count: int, contributor_count: int, first_commit_at: string, last_commit_at: string, contributors: list<string>}|null
     */
    private function historyFacts(Repository $repository): ?array
    {
        $summary = $this->history->summary($repository);

        if ($summary === null) {
            return null;
        }

        return [
            ...$summary,
            'contributors' => array_column($this->history->contributors($repository, 3), 'name'),
        ];
    }

    /**
     * The technologies shown on the „Containere” diagram (with their versions), when it exists.
     *
     * @return list<string>
     */
    private function technologies(Repository $repository): array
    {
        $meta = $repository->diagrams()->where('type', DiagramType::Container)->value('meta');
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        return array_values(array_filter(array_map(
            fn (mixed $technology): ?string => is_array($technology) && is_string($technology['name'] ?? null) ? $technology['name'] : null,
            $meta['technologies'] ?? [],
        )));
    }

    /**
     * The diagram drawn on a slide: „Containere” when it exists (few boxes, readable on a slide),
     * else „Straturi”.
     *
     * @return array{type: string, title: string, caption: string|null, mermaid: string}|null
     */
    private function diagram(Repository $repository): ?array
    {
        foreach ([DiagramType::Container, DiagramType::Layers] as $type) {
            $diagram = $repository->diagrams()->where('type', $type)->first();

            if ($diagram !== null) {
                return [
                    'type' => $type->value,
                    'title' => (string) ($diagram->meta['title'] ?? $type->value),
                    'caption' => $diagram->meta['caption'] ?? null,
                    'mermaid' => $diagram->mermaid,
                ];
            }
        }

        return null;
    }
}
