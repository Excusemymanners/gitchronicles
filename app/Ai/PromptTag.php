<?php

namespace App\Ai;

/**
 * A tag that the app itself writes into a prompt (<context>, <file path="…">, </file>, …), as opposed to
 * the text of the repository that goes inside the tags.
 *
 * A prompt is built as a list of lines. Lines that are plain strings come from the repository and are made
 * safe by PromptText::lines(); lines that are PromptTag objects are ours and stay as written, with their
 * attribute values escaped. That way no repository text can be mistaken for one of our tags
 * (security audit AI-LAYER-05).
 */
final readonly class PromptTag
{
    private function __construct(public string $text) {}

    /**
     * An opening tag, e.g. open('file', ['path' => 'app/User.php']) is <file path="app/User.php">.
     *
     * @param  array<string, string|int>  $attributes
     */
    public static function open(string $name, array $attributes = []): self
    {
        $written = '';

        foreach ($attributes as $attribute => $value) {
            $written .= ' '.$attribute.'="'.PromptText::attribute((string) $value).'"';
        }

        return new self("<{$name}{$written}>");
    }

    /**
     * A closing tag, e.g. close('file') is </file>.
     */
    public static function close(string $name): self
    {
        return new self("</{$name}>");
    }
}
