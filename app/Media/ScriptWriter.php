<?php

namespace App\Media;

use App\Ai\AiDisabled;
use App\Ai\AiException;
use App\Ai\TextGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the script of the presentation video (PresentationScript).
 *
 * With AI, Claude receives the real sources of the repository (README, „Despre proiect”, GitHub; see
 * PresentationFacts::aiContext()) and answers with strict JSON: the opening line, the problem the project
 * solves, two or three things that make it special, how to start and the closing line. Every scene carries
 * a "source": a sentence copied exactly from the context. The server then checks every scene:
 *
 * - the quote really is in the context it sent (compared without differences of spaces and letter case);
 * - every text is short enough for the screen, in Romanian with comma-below ș/ț, without emoji;
 * - the command of „Cum începi” is copied exactly from the README;
 * - the highlighted words are part of the scene's own text.
 *
 * A scene that fails is dropped (and listed in "rejected"); a missing opening or closing is replaced by the
 * template one. Without AI (AiDisabled), when Claude fails, or when the answer is not usable JSON, the whole
 * script is built by TemplateScenes: a shorter video, only from the description and the numbers.
 * The numbers scene is never written by the AI: it comes straight from GitHub and the analysis.
 */
final class ScriptWriter
{
    /**
     * The longest texts the server accepts, in characters: short enough for a phone screen and a 25–35
     * second video. The prompt asks for a little less, because a model does not count characters exactly.
     *
     * @var array<string, int>
     */
    public const LIMITS = [
        'hook' => 65,
        'problem' => 105,
        'feature_title' => 32,
        'feature_text' => 72,
        'start' => 60,
        'cta' => 55,
        'accent' => 30,
    ];

    /**
     * At most this many „De ce e special” scenes.
     */
    public const MAX_FEATURES = 3;

    /**
     * The instructions for Claude (short: the CLI driver passes them on the command line).
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You write the script of a 25-35 second vertical video (like the Instagram reels that present GitHub projects) about ONE repository.
        The audience is curious people, not the project's developers: say what the application does and what makes it special, not how the code is written.
        Write natural, spoken Romanian with correct diacritics (ă, â, î, ș, ț with comma below). A voice reads every text aloud, so use short, clear sentences without Markdown, emoji, URLs, file names or code.

        Use ONLY facts stated in the CONTEXT. Never invent features, numbers, users, comparisons or praise. Every scene has "source": one sentence or phrase copied EXACTLY, character for character, from the CONTEXT (15-200 characters), which proves the scene. If the CONTEXT does not support a scene, use null (or fewer features).

        Answer with ONE JSON object and nothing else:
        {"hook": {"text": "what the project is, one line, max 55 characters", "accent": "1-3 words copied from text", "source": "..."},
         "problem": {"text": "the problem it solves, max 90 characters", "accent": "...", "source": "..."} or null,
         "features": [{"title": "max 26 characters", "text": "max 60 characters", "accent": "...", "source": "..."}] (2 or 3 items, the most special first),
         "start": {"text": "how to start, max 50 characters", "command": "one shell command copied exactly from a README code block", "source": "the same command"} or null,
         "cta": {"text": "closing invitation, max 45 characters", "accent": "...", "source": "..."}}
        All texts together must stay under 70 words. Count the characters: a text over its limit is thrown away.
        PROMPT;

    /**
     * Create the writer.
     */
    public function __construct(private TextGenerator $ai) {}

    /**
     * Write the script: with Claude when possible, otherwise from the template.
     */
    public function write(PresentationFacts $facts): PresentationScript
    {
        if ($facts->sources === []) {
            return $this->templateScript($facts, 'Repository-ul nu are README sau descriere din care să scriem textele.');
        }

        try {
            $result = $this->ai->generate(self::SYSTEM_PROMPT, $this->userPrompt($facts));
        } catch (AiDisabled) {
            return $this->templateScript($facts, 'Generarea cu AI este oprită, așa că textele vin direct din descriere și cifre.');
        } catch (AiException $exception) {
            Log::warning('The video script could not be written by AI.', ['repository' => $facts->fullName(), 'message' => $exception->getMessage()]);

            return $this->templateScript($facts, $exception->getMessage());
        }

        $answer = $this->decode($result->text);

        if ($answer === null) {
            Log::warning('The video script answer is not a JSON object.', ['repository' => $facts->fullName()]);

            return $this->templateScript($facts, 'Răspunsul AI nu a putut fi folosit, așa că textele vin direct din descriere și cifre.');
        }

        [$scenes, $rejected] = $this->scenesFromAnswer($answer, $facts);

        return new PresentationScript(
            scenes: $scenes,
            writer: 'ai',
            model: $result->model,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            rejected: $rejected,
        );
    }

    /**
     * The script made only from the facts, without AI.
     */
    public function templateScript(PresentationFacts $facts, ?string $note = null): PresentationScript
    {
        $scenes = array_values(array_filter([
            TemplateScenes::hook($facts),
            TemplateScenes::start($facts),
            TemplateScenes::proof($facts),
            TemplateScenes::cta($facts),
        ]));

        return new PresentationScript(scenes: $scenes, writer: 'template', note: $note);
    }

    /**
     * What Claude receives: the name of the repository and the context.
     */
    private function userPrompt(PresentationFacts $facts): string
    {
        return "Repository: {$facts->fullName()}\n\nCONTEXT:\n\n{$facts->aiContext()}";
    }

    /**
     * The JSON object of the answer (Claude sometimes wraps it in a ```json block), or null.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $answer = json_decode(substr($text, $start, $end - $start + 1), associative: true);

        return is_array($answer) ? $answer : null;
    }

    /**
     * The scenes of the AI answer that pass the checks, in the order of the video, and the rejected ones.
     *
     * @param  array<string, mixed>  $answer
     * @return array{0: list<array<string, mixed>>, 1: list<array{scene: string, reason: string}>}
     */
    private function scenesFromAnswer(array $answer, PresentationFacts $facts): array
    {
        $rejected = [];
        $check = function (string $scene, mixed $item, array $fields) use ($facts, &$rejected): ?array {
            $result = $this->checkItem($item, $fields, $facts);

            if (is_string($result)) {
                $rejected[] = ['scene' => $scene, 'reason' => $result];

                return null;
            }

            return $result;
        };

        $hook = $check('hook', $answer['hook'] ?? null, ['text' => self::LIMITS['hook']]);
        $problem = isset($answer['problem']) ? $check('problem', $answer['problem'], ['text' => self::LIMITS['problem']]) : null;
        $cta = $check('cta', $answer['cta'] ?? null, ['text' => self::LIMITS['cta']]);

        $features = [];

        foreach (array_slice(is_array($answer['features'] ?? null) ? $answer['features'] : [], 0, self::MAX_FEATURES) as $position => $item) {
            $feature = $check('feature '.($position + 1), $item, ['title' => self::LIMITS['feature_title'], 'text' => self::LIMITS['feature_text']]);

            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        $scenes = [];
        $scenes[] = $hook === null ? TemplateScenes::hook($facts) : [
            'key' => 'hook', 'label' => 'Ce este', 'kicker' => "{$facts->owner} /", 'title' => $facts->name,
            'text' => $hook['text'], 'accent' => $hook['accent'], 'narration' => $hook['text'], 'source' => $hook['source'],
        ];

        if ($problem !== null) {
            $scenes[] = [
                'key' => 'problem', 'label' => 'Ce rezolvă',
                'text' => $problem['text'], 'accent' => $problem['accent'], 'narration' => $problem['text'], 'source' => $problem['source'],
            ];
        }

        foreach ($features as $position => $feature) {
            $scenes[] = [
                'key' => 'feature', 'label' => 'De ce e special', 'index' => $position + 1, 'count' => count($features),
                'title' => $feature['title'], 'text' => $feature['text'], 'accent' => $feature['accent'],
                'narration' => self::sentence($feature['title']).' '.$feature['text'], 'source' => $feature['source'],
            ];
        }

        $scenes[] = $this->startScene($answer['start'] ?? null, $facts, $rejected);
        $scenes[] = TemplateScenes::proof($facts);
        $scenes[] = TemplateScenes::cta($facts, $cta === null ? null : [
            'text' => $cta['text'], 'accent' => $cta['accent'], 'narration' => $cta['text'], 'source' => $cta['source'],
        ]);

        return [array_values(array_filter($scenes)), $rejected];
    }

    /**
     * „Cum începi”: the AI's line with a command copied exactly from the README, or the template scene
     * (the README's first command) when the AI gave none or one the README does not have.
     *
     * @param  list<array{scene: string, reason: string}>  $rejected
     * @return array<string, mixed>|null
     */
    private function startScene(mixed $item, PresentationFacts $facts, array &$rejected): ?array
    {
        if (! is_array($item)) {
            return TemplateScenes::start($facts);
        }

        $text = $this->text($item['text'] ?? null, self::LIMITS['start']);
        $command = is_string($item['command'] ?? null) ? trim($item['command']) : '';

        $reason = match (true) {
            $text === null => 'Câmpul „text” lipsește sau are peste '.self::LIMITS['start'].' de caractere.',
            $command === '' || mb_strlen($command) > 60 => 'Comanda lipsește sau e prea lungă pentru ecran.',
            ! $facts->readmeContains($command) => 'Comanda nu apare exact în README.',
            default => null,
        };

        if ($reason !== null) {
            $rejected[] = ['scene' => 'start', 'reason' => $reason];

            return TemplateScenes::start($facts);
        }

        return [
            'key' => 'start', 'label' => 'Cum începi',
            'text' => $text, 'accent' => $this->accent($item['accent'] ?? null, $text), 'command' => $command,
            'narration' => $text,
            'source' => ['label' => 'README.md', 'quote' => $command, 'url' => $facts->sources['README.md']['url'] ?? null],
        ];
    }

    /**
     * Check one AI scene: every field a short clean text, and a source quote found in the context.
     * Returns the clean scene (fields, "accent", "source") or the reason it was rejected.
     *
     * @param  array<string, int>  $fields  field => longest length
     * @return array<string, mixed>|string
     */
    private function checkItem(mixed $item, array $fields, PresentationFacts $facts): array|string
    {
        if (! is_array($item)) {
            return 'Scena lipsește din răspuns.';
        }

        $scene = [];

        foreach ($fields as $field => $limit) {
            $value = $this->text($item[$field] ?? null, $limit);

            if ($value === null) {
                return "Câmpul „{$field}” lipsește sau are peste {$limit} de caractere.";
            }

            $scene[$field] = $value;
        }

        $source = is_string($item['source'] ?? null) ? $facts->findSource($item['source']) : null;

        if ($source === null) {
            return 'Citatul-sursă nu apare în textele trimise.';
        }

        $scene['accent'] = $this->accent($item['accent'] ?? null, $scene['text']);
        $scene['source'] = $source;

        return $scene;
    }

    /**
     * A clean text no longer than $limit, or null.
     */
    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = RomanianText::clean($value);

        return $text === '' || mb_strlen($text) > $limit ? null : $text;
    }

    /**
     * The highlighted words, only when they are a short piece of the scene's own text.
     */
    private function accent(mixed $value, string $text): ?string
    {
        $accent = is_string($value) ? RomanianText::clean($value) : '';

        return $accent !== '' && mb_strlen($accent) <= self::LIMITS['accent'] && str_contains($text, $accent) ? $accent : null;
    }

    /**
     * A title read as its own sentence: it gets a full stop when it has no ending punctuation.
     */
    private static function sentence(string $title): string
    {
        return Str::endsWith($title, ['.', '!', '?', ':']) ? $title : "{$title}.";
    }
}
