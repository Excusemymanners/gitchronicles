<?php

namespace App\Media\Slides;

use App\Ai\AiDisabled;
use App\Ai\AiException;
use App\Ai\TextGenerator;
use Illuminate\Support\Facades\Log;

/**
 * Improves the wording of the slides that condense a documentation page (what it does, what it offers,
 * one slide per module) with ONE call of the text generator per deck. The answer is strict JSON and is
 * checked slide by slide: a slide whose answer is malformed, too long or says something that is not in
 * its page (a number, a name, a technology) keeps the text the planner made from the page. With AI off
 * (AiDisabled) or on an error, no slide changes.
 */
final class SlideTextWriter
{
    public const MAX_HEADLINE_CHARACTERS = 90;

    public const MAX_BULLET_CHARACTERS = 110;

    public const MAX_BULLET_WORDS = 16;

    private const PAGE_CHARACTERS = 1800;

    /**
     * The instructions for the text generator (short: the CLI driver passes them on the command line).
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You turn pages of a software repository's documentation into the text of presentation slides.
        Use ONLY facts stated in the given page of each slide. Never add features, numbers, names, technologies or praise. Write in the language of the pages.
        For every slide give "headline": one short sentence (at most 12 words) that states the point of the page, and "bullets": 2 to 4 bullets of at most 12 words each. Slide "what" is a statement: give only "headline" (at most 20 words) and no bullets. For slide "features", every bullet is "Name: what it does", taken from the page.
        Answer with ONE JSON object and nothing else: {"slides": {"<id>": {"headline": "...", "bullets": ["..."]}}} with exactly the ids you were given.
        PROMPT;

    public function __construct(private TextGenerator $ai) {}

    /**
     * Improve the slides that condense a page; the others are returned as they are.
     *
     * @param  list<Slide>  $slides
     * @param  array<string, mixed>  $source  SlideSource::collect()
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @return list<Slide>
     */
    public function improve(array $slides, array $source, array $facts): array
    {
        $pages = self::pages($slides, $source);

        if ($pages === []) {
            return $slides;
        }

        try {
            $result = $this->ai->generate(self::SYSTEM_PROMPT, self::prompt($pages));
        } catch (AiDisabled) {
            return $slides;
        } catch (AiException $exception) {
            Log::warning('The slide texts could not be written by AI.', ['repository' => "{$facts['owner']}/{$facts['name']}", 'message' => $exception->getMessage()]);

            return $slides;
        }

        $answer = self::decode($result->text);

        if ($answer === null) {
            Log::warning('The slide text answer is not valid JSON.', ['repository' => "{$facts['owner']}/{$facts['name']}"]);

            return $slides;
        }

        return array_map(function (Slide $slide) use ($answer, $pages): Slide {
            $improved = isset($pages[$slide->id]) ? self::apply($slide, $answer['slides'][$slide->id] ?? null, $pages[$slide->id]) : null;

            return $improved ?? $slide;
        }, $slides);
    }

    /**
     * The page every improvable slide is made from, as plain text: slide id => text.
     *
     * @param  list<Slide>  $slides
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    public static function pages(array $slides, array $source): array
    {
        $pages = [];

        foreach ($slides as $slide) {
            $markdown = match (true) {
                $slide->id === 'what' && $slide->source === 'overview' => $source['overview'] ?? null,
                $slide->id === 'features' => $source['readme'] ?? $source['overview'] ?? null,
                str_starts_with($slide->id, 'module:') => collect($source['modules'] ?? [])->firstWhere('path', substr($slide->id, 7))['markdown'] ?? null,
                default => null,
            };

            if (is_string($markdown) && trim($markdown) !== '') {
                $pages[$slide->id] = DocText::plain($markdown);
            }
        }

        return $pages;
    }

    /**
     * The message for the text generator: the pages, each cut to the part that fits.
     *
     * @param  array<string, string>  $pages
     */
    private static function prompt(array $pages): string
    {
        $items = [];

        foreach ($pages as $id => $text) {
            $items[] = ['id' => $id, 'page' => mb_substr($text, 0, self::PAGE_CHARACTERS)];
        }

        return json_encode(['slides' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The JSON object of the answer (Claude may wrap it in text or a code block), or null.
     *
     * @return array{slides: array<string, mixed>}|null
     */
    public static function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $answer = json_decode(substr($text, $start, $end - $start + 1), associative: true);

        return is_array($answer) && is_array($answer['slides'] ?? null) ? $answer : null;
    }

    /**
     * The slide with the wording of the answer, or null when the answer for it is not usable: malformed,
     * too long, or not grounded in the page.
     *
     * @param  mixed  $answer  the answer for this slide
     * @param  string  $page  the plain text of the page the slide is made from
     */
    public static function apply(Slide $slide, mixed $answer, string $page): ?Slide
    {
        if (! is_array($answer) || ! is_string($answer['headline'] ?? null)) {
            return null;
        }

        $headline = trim($answer['headline']);
        $limit = $slide->layout === 'statement' ? 150 : self::MAX_HEADLINE_CHARACTERS;

        if ($headline === '' || mb_strlen($headline) > $limit || ! self::isGrounded($headline, $page)) {
            return null;
        }

        if ($slide->layout === 'statement') {
            return $slide->with(['headline' => $headline]);
        }

        $bullets = $answer['bullets'] ?? null;

        if (! is_array($bullets) || count($bullets) < 2 || count($bullets) > SlidePlanner::MAX_BULLETS || array_filter($bullets, 'is_string') !== $bullets) {
            return null;
        }

        foreach ($bullets as $bullet) {
            $words = preg_split('/\s+/u', trim($bullet)) ?: [];

            if (trim($bullet) === '' || mb_strlen($bullet) > self::MAX_BULLET_CHARACTERS || count($words) > self::MAX_BULLET_WORDS || ! self::isGrounded($bullet, $page)) {
                return null;
            }
        }

        return $slide->with(['headline' => $headline, 'bullets' => array_map('trim', $bullets)]);
    }

    /**
     * Whether a text says nothing that is not in the page: every number, every `code` word, every
     * identifier (with a dot, slash, underscore or inner capital) and every capitalised word that is
     * not the first of the text must occur in the page.
     */
    public static function isGrounded(string $text, string $page): bool
    {
        $page = mb_strtolower($page);
        $words = [];

        preg_match_all('/`([^`]+)`|\b\d[\d.,]*\b|\b[\w.\/-]*[._\/][\w.\/-]*\b|\b[a-z]+[A-Z]\w*\b/u', $text, $matches);

        foreach ($matches[0] as $token) {
            $words[] = trim($token, '`.,');
        }

        preg_match_all('/(?<=[a-z0-9,;:] )[A-Z][\w+#.-]{2,}/u', $text, $capitalised);
        array_push($words, ...$capitalised[0]);

        foreach ($words as $word) {
            if ($word !== '' && ! str_contains($page, mb_strtolower($word))) {
                return false;
            }
        }

        return true;
    }
}
