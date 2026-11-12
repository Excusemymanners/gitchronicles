<?php

namespace App\Documentation;

use Illuminate\Support\Str;

/**
 * Keeps the links of text that Claude wrote from a repository's content on this site's own ground.
 *
 * Claude writes documentation and answers from text of a repository that anyone can write, so a hostile
 * README could make it add a link or an image to a page of the attacker (phishing, tracking pixels) that then
 * stays in the saved page and in the downloadable README. The system prompt already asks for GitHub links
 * of the repository only; this class enforces it (security audit AI-LAYER-06):
 *
 * - allowed: a link inside the page (#anchor), a page of this app (/repositories/{id}…) and a link to the
 *   repository on GitHub (its file, folder and repository addresses from SourceLinks);
 * - any other link or image, an autolink <https://…> and a bare address become plain text: the address is
 *   written in `code`, so Markdown cannot turn it into a link again, and an image is just its description;
 * - code blocks and `inline code` are not touched.
 */
final class LinkGuard
{
    /**
     * Whether a destination (the part inside the parentheses of a Markdown link) may stay a link.
     */
    public function allows(string $destination, SourceLinks $links): bool
    {
        $destination = trim($destination, " \t<>");

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $destination) === 1) {
            return false;
        }

        if (str_starts_with($destination, '#') || preg_match('~^/repositories/\d+(?:[/?#]|$)~', $destination) === 1) {
            return true;
        }

        $repository = "https://github.com/{$links->owner}/{$links->name}";

        foreach ([$links->fileBase(), $links->folder('/').'/', $links->cloneUrl()] as $prefix) {
            if (str_starts_with(strtolower($destination), strtolower($prefix))) {
                return true;
            }
        }

        return preg_match('~^'.preg_quote($repository, '~').'(?:[/?#]|$)~i', $destination) === 1;
    }

    /**
     * The Markdown with every link that is not allowed turned into plain text.
     *
     * @param  int  $removed  how many links, images and addresses were turned into text
     */
    public function clean(string $markdown, SourceLinks $links, int &$removed = 0): string
    {
        $removed = 0;

        // Taking a link out can leave the pieces of another one (a link around an image), so clean again
        // until nothing more changes.
        for ($pass = 0; $pass < 4; $pass++) {
            $before = $removed;
            $markdown = $this->cleanOnce($markdown, $links, $removed);

            if ($removed === $before) {
                break;
            }
        }

        return $markdown;
    }

    /**
     * The addresses of the links and images that the Markdown would really produce (it is rendered the way
     * GitHub flavored Markdown is, without raw HTML) and that are not allowed. Empty when the page is safe.
     *
     * @return list<string>
     */
    public function unsafeAddresses(string $markdown, SourceLinks $links): array
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        preg_match_all('/\b(?:href|src)="([^"]*)"/i', $html, $matches);

        return array_values(array_filter(
            array_map('html_entity_decode', $matches[1]),
            fn (string $address): bool => ! str_starts_with($address, 'mailto:') && ! $this->allows($address, $links),
        ));
    }

    private function cleanOnce(string $markdown, SourceLinks $links, int &$removed): string
    {
        // Code (fenced blocks, `inline code`) stays as it is; everything between the code is cleaned.
        $code = '/^(?:```|~~~)[^\n]*\n.*?^(?:```|~~~)[ \t]*$|(?<!`)(`+)(?!`).+?(?<!`)\1(?!`)/msu';
        preg_match_all($code, $markdown, $matches, PREG_OFFSET_CAPTURE);

        $cleaned = '';
        $position = 0;

        foreach ($matches[0] as [$block, $offset]) {
            $cleaned .= $this->cleanText(substr($markdown, $position, $offset - $position), $links, $removed).$block;
            $position = $offset + strlen($block);
        }

        $cleaned .= $this->cleanText(substr($markdown, $position), $links, $removed);

        return $cleaned;
    }

    /**
     * Clean a piece of text that has no code in it.
     */
    private function cleanText(string $text, SourceLinks $links, int &$removed): string
    {
        // Reference definitions ("[1]: https://…") of a destination that is not allowed disappear.
        $text = preg_replace_callback('/^ {0,3}\[[^\]\n]+\]:[ \t]*(\S+)[^\n]*$\n?/mu', function (array $match) use ($links, &$removed): string {
            if ($this->allows($match[1], $links)) {
                return $match[0];
            }

            $removed++;

            return '';
        }, $text) ?? $text;

        $pattern = '/(?<link>(?<image>!?)\[(?<label>(?:[^\[\]\n]|!\[[^\]\n]*\]\([^)\n]*\))*)\]\((?<destination>[^)\n]*)\))'
            .'|(?<auto><[a-z][a-z0-9+.-]*:[^>\s]*>)'
            .'|(?<bare>(?:https?:\/\/|www\.)[^\s<>()\[\]]+)/iu';

        return preg_replace_callback($pattern, function (array $match) use ($links, &$removed): string {
            if (($match['link'] ?? '') !== '') {
                if ($this->allows(explode(' ', trim($match['destination']))[0], $links)) {
                    // The text of an allowed link may hold an image or an address of its own.
                    return $match['image'].'['.$this->cleanText($match['label'], $links, $removed).']('.$match['destination'].')';
                }

                $removed++;
                $label = $this->cleanText($match['label'], $links, $removed);

                return $label === '' ? '' : $label;
            }

            $address = ($match['auto'] ?? '') !== '' ? trim($match['auto'], '<>') : $match['bare'];

            if ($this->allows($address, $links)) {
                return $match[0];
            }

            $removed++;

            return '`'.$address.'`';
        }, $text) ?? $text;
    }
}
