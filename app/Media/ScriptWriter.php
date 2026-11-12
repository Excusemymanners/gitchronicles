<?php

namespace App\Media;

use App\Ai\AiDisabled;
use App\Ai\AiException;
use App\Ai\TextGenerator;
use App\Enums\MediaLanguage;
use Illuminate\Support\Facades\Log;

/**
 * Writes the script of the presentation video (PresentationScript): the story of the repository from
 * start to finish, in English and in Romanian from ONE call.
 *
 * With AI, Claude receives the real sources of the repository (README, „Despre proiect”, GitHub; see
 * PresentationFacts::aiContext()) and answers with strict JSON: the opening line, the problem the
 * project solves, two to four things it does and how to start — every line once in each language.
 * Every scene carries its anchor: a "quote" copied exactly from the context and, for a feature, the
 * README "heading" it stands under. The server then checks every scene:
 *
 * - the quote really is in the context it sent (compared without differences of spaces and letter case);
 * - both texts are there and short enough to be said in the scene's time, without emoji, with comma-below ș/ț;
 * - a number is said only when the quote (or the command) has it: the AI never brings its own numbers;
 * - the heading exists in the README (an unknown heading is only forgotten, the scene stays);
 * - the command of „Cum începi” is copied exactly from the README.
 *
 * A scene that fails is dropped (and listed in "rejected"), never shown; a missing opening is replaced by
 * the template one. „Cum e construit”, „În cifre” and the closing are never written by the AI: they come
 * straight from the code analysis, GitHub and the git history (TemplateScenes).
 *
 * Without AI (AiDisabled), when Claude fails, or when the answer is not usable JSON, the whole script is
 * built by TemplateScenes: shorter, from the description, the README's sections and the numbers.
 *
 * The validated answer is kept with the script, together with the hash of what Claude was given: while the
 * sources do not change, the same texts are used again (write() with $previous) and no new call is made.
 */
final class ScriptWriter
{
    /**
     * The longest texts the server accepts, in characters: about 13 are said in a second. The prompt
     * asks for a little less, because a model does not count characters exactly.
     *
     * @var array<string, int>
     */
    public const LIMITS = [
        'hook' => 110,
        'problem' => 180,
        'feature' => 170,
        'feature_title' => 34,
        'start' => 120,
    ];

    /**
     * At most this many „Ce face” scenes.
     */
    public const MAX_FEATURES = 4;

    /**
     * The instructions for Claude (short: the CLI driver passes them on the command line).
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You write the voice-over of a 45-60 second vertical video (like the Instagram reels that present GitHub projects) about ONE repository. On screen the viewer sees the repository's real GitHub page being scrolled; your lines are read aloud by a voice and shown as captions.
        Tell the story from start to finish for curious people who have never seen the project: what it is, which problem it solves, what it does and what it is good at.
        Write every line twice: "en" in natural spoken English and "ro" in natural spoken Romanian with correct diacritics (ă, â, î, ș, ț with comma below). Both say the same thing, each phrased the way a native presenter would say it, not translated word for word. Short, clear sentences. No Markdown, emoji, URLs, file names or code; never read a command aloud. Keep technology names as they are written.

        Use ONLY facts stated in the CONTEXT. Never invent features, numbers, users, comparisons or praise. Every scene has "quote": one sentence or phrase copied EXACTLY, character for character, from the CONTEXT (15-200 characters), which proves what you say. For a feature, copy the quote from the README section that describes it and give that section's heading in "heading" (copied exactly from a README line that starts with #, without the #; null when there is none). Say a number only if the scene's quote contains it. If the CONTEXT does not support a scene, use null (or fewer features).

        Answer with ONE JSON object and nothing else:
        {"hook": {"en": "what the project is and what it promises, one sentence, max 90 characters", "ro": "...", "quote": "..."},
         "problem": {"en": "the problem it solves, max 150 characters", "ro": "...", "quote": "..."} or null,
         "features": [{"heading": "...", "title_en": "max 28 characters", "title_ro": "max 28 characters", "en": "what it does, concretely, max 140 characters", "ro": "...", "quote": "..."}] (2 to 4 items, the most important first),
         "start": {"en": "how to try it, max 100 characters", "ro": "...", "command": "one shell command copied exactly from a README code block"} or null}
        Count the characters: a text over its limit is thrown away.
        PROMPT;

    /**
     * Create the writer.
     */
    public function __construct(private TextGenerator $ai) {}

    /**
     * Write the script: with Claude when possible, otherwise from the template.
     *
     * @param  array{writer?: string, answer?: array<string, mixed>|null, context_hash?: string|null, model?: string|null}|null  $previous  the script of the existing video (PresentationScript::toArray()): its AI answer is used again when the sources did not change
     */
    public function write(PresentationFacts $facts, ?array $previous = null): PresentationScript
    {
        if ($facts->sources === []) {
            return $this->templateScript($facts, 'Repository-ul nu are README sau descriere din care să scriem textele.');
        }

        $contextHash = self::contextHash($facts);

        if (($previous['writer'] ?? null) === 'ai' && ($previous['context_hash'] ?? null) === $contextHash && is_array($previous['answer'] ?? null)) {
            [$scenes, $rejected] = $this->scenesFromAnswer($previous['answer'], $facts);

            return new PresentationScript($scenes, 'ai', $previous['model'] ?? null, rejected: $rejected, answer: $previous['answer'], contextHash: $contextHash);
        }

        try {
            $result = $this->ai->generate(self::SYSTEM_PROMPT, self::userPrompt($facts));
        } catch (AiDisabled) {
            return $this->templateScript($facts, 'Generarea cu AI este oprită, așa că textele vin direct din descriere, din README și din cifre.');
        } catch (AiException $exception) {
            Log::warning('The video script could not be written by AI.', ['repository' => $facts->fullName(), 'message' => $exception->getMessage()]);

            return $this->templateScript($facts, $exception->getMessage());
        }

        $answer = $this->decode($result->text);

        if ($answer === null) {
            Log::warning('The video script answer is not a JSON object.', ['repository' => $facts->fullName()]);

            return $this->templateScript($facts, 'Răspunsul AI nu a putut fi folosit, așa că textele vin direct din descriere, din README și din cifre.');
        }

        [$scenes, $rejected] = $this->scenesFromAnswer($answer, $facts);

        return new PresentationScript(
            scenes: $scenes,
            writer: 'ai',
            model: $result->model,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            rejected: $rejected,
            answer: $answer,
            contextHash: $contextHash,
        );
    }

    /**
     * The script made only from the facts, without AI.
     */
    public function templateScript(PresentationFacts $facts, ?string $note = null): PresentationScript
    {
        $scenes = array_values(array_filter([
            TemplateScenes::hook($facts),
            TemplateScenes::tour($facts),
            TemplateScenes::built($facts),
            TemplateScenes::proof($facts),
            TemplateScenes::start($facts),
            TemplateScenes::cta($facts),
        ]));

        return new PresentationScript(scenes: $scenes, writer: 'template', note: $note);
    }

    /**
     * The hash of everything Claude is given for a repository: the instructions and the context.
     */
    public static function contextHash(PresentationFacts $facts): string
    {
        return hash('sha256', self::SYSTEM_PROMPT."\n".self::userPrompt($facts));
    }

    /**
     * What Claude receives: the name of the repository and the context (also hashed by contextHash()).
     */
    private static function userPrompt(PresentationFacts $facts): string
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
     * The scenes of the AI answer that pass the checks, in the order of the story, with the scenes made
     * from data between them, and the rejected ones.
     *
     * @param  array<string, mixed>  $answer
     * @return array{0: list<array<string, mixed>>, 1: list<array{scene: string, reason: string}>}
     */
    private function scenesFromAnswer(array $answer, PresentationFacts $facts): array
    {
        $rejected = [];
        $check = function (string $scene, mixed $item, int $limit) use ($facts, &$rejected): ?array {
            $result = $this->checkItem($item, $limit, $facts);

            if (is_string($result)) {
                $rejected[] = ['scene' => $scene, 'reason' => $result];

                return null;
            }

            return $result;
        };

        $hook = $check('hook', $answer['hook'] ?? null, self::LIMITS['hook']);
        $problem = isset($answer['problem']) ? $check('problem', $answer['problem'], self::LIMITS['problem']) : null;
        $features = [];

        foreach (array_slice(is_array($answer['features'] ?? null) ? array_values($answer['features']) : [], 0, self::MAX_FEATURES) as $position => $item) {
            $feature = $check('feature '.($position + 1), $item, self::LIMITS['feature']);

            if ($feature !== null) {
                $features[] = [...$feature, 'titles' => $this->titles($item)];
            }
        }

        $scenes = [TemplateScenes::hook($facts, $hook)];

        if ($problem !== null) {
            $scenes[] = [
                'key' => 'problem', 'heading' => null, 'quote' => $problem['source']['quote'], 'source' => $problem['source'],
                ...TemplateScenes::spoken('problem', $problem['en'], $problem['ro']),
            ];
        }

        foreach ($features as $position => $feature) {
            $spoken = TemplateScenes::spoken('feature', $feature['en'], $feature['ro']);

            $scenes[] = [
                'key' => 'feature', 'index' => $position + 1, 'count' => count($features),
                'heading' => $feature['heading'], 'quote' => $feature['source']['quote'], 'source' => $feature['source'],
                'en' => [...$spoken['en'], 'title' => $feature['titles']['en']],
                'ro' => [...$spoken['ro'], 'title' => $feature['titles']['ro']],
            ];
        }

        $scenes[] = TemplateScenes::built($facts);
        $scenes[] = TemplateScenes::proof($facts);
        $scenes[] = $this->startScene($answer['start'] ?? null, $facts, $rejected);
        $scenes[] = TemplateScenes::cta($facts);

        return [array_values(array_filter($scenes)), $rejected];
    }

    /**
     * „Cum începi”: the AI's lines with a command copied exactly from the README, or the template scene
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

        $english = $this->text($item['en'] ?? null, self::LIMITS['start']);
        $romanian = $this->text($item['ro'] ?? null, self::LIMITS['start']);
        $command = is_string($item['command'] ?? null) ? trim($item['command']) : '';

        $reason = match (true) {
            $english === null || $romanian === null => 'Textul lipsește într-una din limbi sau are peste '.self::LIMITS['start'].' de caractere.',
            $command === '' || mb_strlen($command) > 60 => 'Comanda lipsește sau e prea lungă pentru ecran.',
            ! $facts->readmeContains($command) => 'Comanda nu apare exact în README.',
            default => $this->inventedNumber([$english, $romanian], $command),
        };

        if ($reason !== null) {
            $rejected[] = ['scene' => 'start', 'reason' => $reason];

            return TemplateScenes::start($facts);
        }

        return TemplateScenes::start($facts, ['en' => $english, 'ro' => $romanian, 'command' => $command]);
    }

    /**
     * Check one AI scene: a short clean text in each language, a quote found in the context and no number
     * the quote does not have. Returns the clean scene ("en", "ro", "heading", "source") or the reason it
     * was rejected.
     *
     * @return array{en: string, ro: string, heading: string|null, source: array{label: string, quote: string, url: string|null}}|string
     */
    private function checkItem(mixed $item, int $limit, PresentationFacts $facts): array|string
    {
        if (! is_array($item)) {
            return 'Scena lipsește din răspuns.';
        }

        $texts = [];

        foreach (MediaLanguage::cases() as $language) {
            $text = $this->text($item[$language->value] ?? null, $limit);

            if ($text === null) {
                return "Textul în {$language->label()} lipsește sau are peste {$limit} de caractere.";
            }

            $texts[$language->value] = $text;
        }

        $source = is_string($item['quote'] ?? null) ? $facts->findSource($item['quote']) : null;

        if ($source === null) {
            return 'Citatul-sursă nu apare în textele trimise.';
        }

        $invented = $this->inventedNumber($texts, $source['quote']);

        if ($invented !== null) {
            return $invented;
        }

        return [
            'en' => $texts['en'],
            'ro' => $texts['ro'],
            'heading' => $facts->findHeading(is_string($item['heading'] ?? null) ? $item['heading'] : null),
            'source' => $source,
        ];
    }

    /**
     * The reason to reject texts that say a number their quote does not contain, or null when every
     * number is backed by the quote.
     *
     * @param  array<array-key, string>  $texts
     */
    private function inventedNumber(array $texts, string $quote): ?string
    {
        preg_match_all('/\d+/', $quote, $allowed);

        foreach ($texts as $text) {
            preg_match_all('/\d+/', $text, $said);

            foreach ($said[0] as $number) {
                if (! in_array($number, $allowed[0], true)) {
                    return "Textul spune cifra {$number}, care nu apare în citatul-sursă.";
                }
            }
        }

        return null;
    }

    /**
     * The short titles of a feature, per language; a missing or long one becomes null (the scene keeps
     * its label only).
     *
     * @return array{en: string|null, ro: string|null}
     */
    private function titles(mixed $item): array
    {
        return [
            'en' => $this->text($item['title_en'] ?? null, self::LIMITS['feature_title']),
            'ro' => $this->text($item['title_ro'] ?? null, self::LIMITS['feature_title']),
        ];
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
}
