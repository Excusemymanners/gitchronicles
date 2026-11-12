<?php

namespace App\Ai;

/**
 * Puts text from a repository (code, README, file names, the visitor's question) inside a prompt
 * safely. Every prompt of the app wraps that text in tags (<context>, <file path="…">, <fragment>,
 * <question>, …) and tells Claude that what is inside them is data, never instructions. That promise
 * holds only if the data cannot write the tags itself, so:
 *
 * - data(): any opening or closing tag with one of our names gets a backslash after "<"
 *   ("</context>" → "<\/context>", "<question>" → "<\question>"), so a README cannot close the data
 *   block and continue as instructions; everything else stays exactly as it was;
 * - attribute(): a value written inside a tag (a file path) has its quotes and angle brackets
 *   escaped, so it cannot end the attribute or the tag.
 *
 * Used by every prompt builder: AiDocumentationWriter, FlowDiagramWriter, AnswerQuestion and the
 * video script (PresentationFacts / ScriptWriter). Why: security audit AI-LAYER-05 and AI-LAYER-07.
 */
final class PromptText
{
    /**
     * The tag names used by the prompts of the app.
     */
    public const TAGS = [
        'context', 'file', 'readme', 'manifest', 'source', 'slide', 'slides', 'script', 'facts', 'insight', 'diagram',
        'overview', 'fragments', 'fragment', 'conversation', 'message', 'question',
    ];

    /**
     * Repository text for the inside of a tag: our tag names can no longer open or close a tag.
     */
    public static function data(string $text): string
    {
        return (string) preg_replace('~<(/?)('.implode('|', self::TAGS).')\b~i', '<\\\\$1$2', $text);
    }

    /**
     * A prompt from its lines: a plain string is repository text (made safe with data()), a PromptTag is
     * one of our own tags and stays as it is. Lines are joined with newlines.
     *
     * @param  list<string|PromptTag>  $lines
     */
    public static function lines(array $lines): string
    {
        return implode("\n", array_map(
            fn (string|PromptTag $line): string => $line instanceof PromptTag ? $line->text : self::data($line),
            $lines,
        ));
    }

    /**
     * A value for a tag attribute, e.g. path="…": quotes, "<", ">" and "&" become entities.
     */
    public static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
