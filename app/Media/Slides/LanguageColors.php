<?php

namespace App\Media\Slides;

/**
 * The colours GitHub gives to the most common languages (the dot next to a language in a repository's
 * sidebar). It is the accent of a deck when the repository has no logo to take a colour from.
 */
final class LanguageColors
{
    private const COLORS = [
        'c' => '#555555', 'c#' => '#178600', 'c++' => '#f34b7d', 'clojure' => '#db5855', 'css' => '#663399',
        'dart' => '#00b4ab', 'dockerfile' => '#384d54', 'elixir' => '#6e4a7e', 'go' => '#00add8', 'haskell' => '#5e5086',
        'html' => '#e34c26', 'java' => '#b07219', 'javascript' => '#f1e05a', 'jupyter notebook' => '#da5b0b',
        'kotlin' => '#a97bff', 'lua' => '#000080', 'makefile' => '#427819', 'objective-c' => '#438eff', 'php' => '#4f5d95',
        'python' => '#3572a5', 'r' => '#198ce7', 'ruby' => '#701516', 'rust' => '#dea584', 'scala' => '#c22d40',
        'shell' => '#89e051', 'svelte' => '#ff3e00', 'swift' => '#f05138', 'typescript' => '#3178c6', 'vue' => '#41b883',
        'zig' => '#ec915c',
    ];

    /**
     * The GitHub colour of a language, or null when it is not in the list.
     */
    public static function for(?string $language): ?string
    {
        return $language === null ? null : (self::COLORS[strtolower(trim($language))] ?? null);
    }
}
