<?php

namespace App\Documentation;

use Illuminate\Support\Str;

/**
 * Two things a README says that the documentation pages need and the writers would otherwise have to
 * find in a long excerpt: the problem the project addresses (the text under a heading like "Why" or
 * "Motivation") and the shell commands of its setup sections (the code blocks under "Installation",
 * "Getting started", "Quick start", ...). Both are copied from the README, never written by us, so a
 * README that says nothing gives null and an empty list, and the pages then leave the section out.
 */
final class ReadmeSections
{
    /**
     * Headings of a README section that explains the problem: "Why", "Motivation", "The problem", ...
     */
    private const PROBLEM_HEADINGS = '/\A(why\b|the problem|problem|what problem|motivation|background|rationale|the challenge|why use|why we)/iu';

    /**
     * Headings of a README section that tells how to set the project up or start it.
     */
    private const SETUP_HEADINGS = '/\A(install(ation)?|getting started|quick ?start|set ?up|usage|build(ing)?|running|how to run|requirements|prerequisites|development)\b/iu';

    /**
     * Languages of a code block that holds shell commands (a block without a language counts too).
     */
    private const SHELL_LANGUAGES = ['', 'bash', 'sh', 'shell', 'console', 'zsh', 'terminal', 'cmd', 'powershell'];

    /**
     * The problem text is cut at this many characters, at the end of a paragraph when possible.
     */
    public const MAX_PROBLEM_CHARACTERS = 700;

    /**
     * At most this many command lines are taken from the README.
     */
    public const MAX_SETUP_COMMANDS = 15;

    /**
     * The text under the first heading that explains the problem, without code blocks, or null.
     */
    public static function problem(string $markdown): ?string
    {
        $paragraphs = [];

        foreach (self::sections($markdown, self::PROBLEM_HEADINGS) as $section) {
            foreach (preg_split('/\R{2,}/', trim(preg_replace('/^(```|~~~).*?^\1[ \t]*$/ms', '', $section['body']) ?? '')) ?: [] as $paragraph) {
                $paragraph = trim(preg_replace('/\s+/', ' ', $paragraph) ?? '');

                if ($paragraph !== '' && ! str_starts_with($paragraph, '|') && ! str_starts_with($paragraph, '![') && ! str_starts_with($paragraph, '<')) {
                    $paragraphs[] = $paragraph;
                }
            }

            if ($paragraphs !== []) {
                break;
            }
        }

        $text = '';

        foreach ($paragraphs as $paragraph) {
            if ($text !== '' && mb_strlen($text) + mb_strlen($paragraph) > self::MAX_PROBLEM_CHARACTERS) {
                break;
            }

            $text .= ($text === '' ? '' : "\n\n").$paragraph;
        }

        return $text === '' ? null : Str::limit($text, self::MAX_PROBLEM_CHARACTERS, '…');
    }

    /**
     * The command lines of the shell code blocks under the setup headings, exactly as written (a leading
     * "$ " prompt and comment lines are dropped), in the order of the README.
     *
     * @return list<string>
     */
    public static function setupCommands(string $markdown): array
    {
        $commands = [];

        foreach (self::sections($markdown, self::SETUP_HEADINGS) as $section) {
            preg_match_all('/^(?:```|~~~)[ \t]*([\w-]*)[^\n]*\R(.*?)^(?:```|~~~)[ \t]*$/ms', $section['body'], $blocks, PREG_SET_ORDER);

            foreach ($blocks as $block) {
                if (! in_array(strtolower($block[1]), self::SHELL_LANGUAGES, true)) {
                    continue;
                }

                foreach (preg_split('/\R/', $block[2]) ?: [] as $line) {
                    $line = trim(preg_replace('/\A\s*[$>]\s+/', '', $line) ?? '');

                    if ($line === '' || str_starts_with($line, '#') || mb_strlen($line) > 200 || in_array($line, $commands, true)) {
                        continue;
                    }

                    $commands[] = $line;
                }
            }
        }

        return array_slice($commands, 0, self::MAX_SETUP_COMMANDS);
    }

    /**
     * The sections whose heading matches the pattern: the text up to the next heading of the same or a
     * higher level (deeper headings belong to the section). Lines inside code blocks are never headings.
     *
     * @return list<array{heading: string, body: string}>
     */
    private static function sections(string $markdown, string $headingPattern): array
    {
        $sections = [];
        $current = null;
        $inCode = false;

        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/\A\s{0,3}(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;
            }

            if (! $inCode && preg_match('/\A(#{1,6})\s+(.+?)\s*#*\s*\z/u', $line, $heading) === 1) {
                $level = strlen($heading[1]);

                if ($current !== null && $level <= $current['level']) {
                    $sections[] = ['heading' => $current['heading'], 'body' => $current['body']];
                    $current = null;
                }

                if ($current === null && preg_match($headingPattern, trim($heading[2], " \t*_`")) === 1) {
                    $current = ['level' => $level, 'heading' => $heading[2], 'body' => ''];
                }

                continue;
            }

            if ($current !== null) {
                $current['body'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $sections[] = ['heading' => $current['heading'], 'body' => $current['body']];
        }

        return $sections;
    }
}
