<?php

namespace App\Media;

use App\Ai\PromptTag;
use Illuminate\Support\Str;

/**
 * Everything the video and the slides may say about a repository, gathered by FactsCollector from real
 * data only: the clone's README, the generated „Despre proiect” page, GitHub's answer, the code analysis,
 * the git history and the diagrams. A fact that is not known is null or an empty list, and the scene or
 * the slide that would show it is left out.
 *
 * The texts the AI may quote (the "sources": README, „Despre proiect”, GitHub) are kept here too, so the
 * script check (ScriptWriter) can tell where each quoted sentence comes from.
 */
final readonly class PresentationFacts
{
    /**
     * @param  string|null  $description  one sentence about the project: GitHub's description, else the README's first paragraph
     * @param  array<string, array{text: string, url: string|null}>  $sources  the texts sent to the AI, by label („README.md”, „Despre proiect”, „GitHub”)
     * @param  array{stars: int, forks: int, language: string|null, license: string|null, topics: list<string>, homepage: string|null, description: string|null}|null  $github
     * @param  list<array{name: string, files: int, lines: int, percent: int}>  $languages  from the code analysis, most lines first
     * @param  list<array{path: string, files: int, lines: int}>  $modules  the biggest modules with code
     * @param  array{files: int, lines: int, modules: int}  $stats
     * @param  array{commit_count: int, contributor_count: int, first_commit_at: string, last_commit_at: string, contributors: list<string>}|null  $history
     * @param  array{command: string, url: string|null}|null  $startCommand  the first shell command of a README code block
     * @param  list<array{command: string, source: string}>  $commands  the commands the manifests define (ProjectFacts)
     * @param  list<string>  $technologies  the technologies of the „Containere” diagram ("Laravel 13", "React 19")
     * @param  array{type: string, title: string, caption: string|null, mermaid: string}|null  $diagram  the diagram for the slides
     * @param  list<string>  $headings  the headings of the README, in order (the sections a scene can be about)
     */
    public function __construct(
        public string $owner,
        public string $name,
        public string $url,
        public ?string $sha,
        public ?string $description,
        public array $sources,
        public ?array $github,
        public ?string $license,
        public array $languages,
        public array $modules,
        public array $stats,
        public ?array $history,
        public ?array $startCommand,
        public array $commands,
        public array $technologies,
        public ?array $diagram,
        public array $headings = [],
    ) {}

    /**
     * "owner/name", as GitHub writes it.
     */
    public function fullName(): string
    {
        return "{$this->owner}/{$this->name}";
    }

    /**
     * The repository's address without "https://", for the screen: github.com/owner/name.
     */
    public function shortUrl(): string
    {
        return Str::after($this->url, '://');
    }

    /**
     * The lines sent to the AI (see PromptText::lines()): every source in a <source label="…"> tag. Every quote of the script must be a piece of it.
     *
     * @return list<string|PromptTag>
     */
    public function aiContext(): array
    {
        $lines = [];

        foreach ($this->sources as $label => $source) {
            $lines[] = PromptTag::open('source', ['label' => $label]);
            $lines[] = $source['text'];
            $lines[] = PromptTag::close('source');
        }

        return $lines;
    }

    /**
     * Where a quote comes from: the first source that contains it (compared without differences of
     * spaces, letter case, cedillas, quotation marks or Markdown emphasis), or null when no source has it.
     * A very short quote proves nothing, so it is never accepted.
     *
     * @return array{label: string, quote: string, url: string|null}|null
     */
    public function findSource(string $quote): ?array
    {
        $needle = self::comparable($quote);

        if (mb_strlen($needle) < 12) {
            return null;
        }

        foreach ($this->sources as $label => $source) {
            if (str_contains(self::comparable($source['text']), $needle)) {
                return ['label' => $label, 'quote' => RomanianText::clean($quote), 'url' => $source['url']];
            }
        }

        return null;
    }

    /**
     * Whether the README has this text (for the command of the „Cum începi” scene, which must be copied exactly).
     */
    public function readmeContains(string $text): bool
    {
        $readme = $this->sources['README.md']['text'] ?? null;

        return $readme !== null && trim($text) !== '' && str_contains(Str::squish($readme), Str::squish($text));
    }

    /**
     * The README heading with this text (compared like a quote), exactly as the README writes it, or null.
     */
    public function findHeading(?string $text): ?string
    {
        $needle = PageCapture::comparable((string) $text);

        foreach ($this->headings as $heading) {
            if ($needle !== '' && PageCapture::comparable($heading) === $needle) {
                return $heading;
            }
        }

        return null;
    }

    /**
     * What the video shows and says, in the form it shows it, as one hash. GenerateMedia compares it with
     * the hash of the existing video: after an update that changed nothing the video is built from (the
     * sources' text, the names, the numbers as they are rounded for the voice, the month of the last
     * commit), the video is not rendered again.
     */
    public function videoFingerprint(): string
    {
        $rounded = fn (?int $number): ?string => $number === null ? null : EnglishText::spokenCount($number, 'x', 'x');

        return hash('sha256', json_encode([
            $this->owner, $this->name, $this->description, $this->license, $this->headings,
            array_map(fn (array $source): string => $source['text'], $this->sources),
            $rounded($this->github['stars'] ?? null), $rounded($this->github['forks'] ?? null),
            array_map(fn (array $language): array => [$language['name'], $language['percent']], $this->languages),
            array_column($this->modules, 'path'), $this->stats['modules'],
            $rounded($this->history['commit_count'] ?? null), $rounded($this->history['contributor_count'] ?? null),
            isset($this->history['last_commit_at']) ? substr($this->history['last_commit_at'], 0, 7) : null,
            $this->startCommand['command'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * A text reduced to what matters for comparing quotes: lower case, comma-below letters, no Markdown
     * emphasis or quotation marks, one space between words.
     */
    public static function comparable(string $text): string
    {
        $text = mb_strtolower(RomanianText::commaBelow($text));
        $text = str_replace(['*', '`', '_', '~', '"', '„', '”', '“', '«', '»', '’', '‘', "'"], '', $text);

        return Str::squish($text);
    }

    /**
     * The facts without the long source texts, to keep with the slides job and the generated media.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'owner' => $this->owner,
            'name' => $this->name,
            'url' => $this->url,
            'sha' => $this->sha,
            'description' => $this->description,
            'github' => $this->github,
            'license' => $this->license,
            'languages' => $this->languages,
            'modules' => $this->modules,
            'stats' => $this->stats,
            'history' => $this->history,
            'start_command' => $this->startCommand,
            'commands' => $this->commands,
            'technologies' => $this->technologies,
            'diagram' => $this->diagram,
        ];
    }
}
