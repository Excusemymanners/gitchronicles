<?php

namespace App\Media;

use Illuminate\Support\Str;

/**
 * The photo of a repository's real GitHub page (made by renderers/capture/github.mjs, kept by
 * GitHubCapture) and the map of what is where on it: the name, the description, the stars and forks,
 * the file list, the latest commit, the language bar, and every heading, image and paragraph of the
 * README. Positions are in CSS pixels of the page; the photo is "scale" times finer.
 *
 * The video scrolls this photo and frames these real elements, so a scene can point only at something
 * that is truly on the page: locate() finds the README block that contains a quote, and returns null
 * when the page does not have it.
 *
 * A capture that could not be made is still an object (missing()), with the reason: the video then
 * falls back to its typographic scenes and the reason is shown on the page.
 */
final readonly class PageCapture
{
    /**
     * The fewest words of a quote that must be found together in one README block.
     */
    private const MIN_WORDS = 4;

    /**
     * @param  string|null  $directory  the absolute folder of the tiles and images, or null when there is no capture
     * @param  array<string, mixed>  $data  the decoded landmarks.json
     * @param  string|null  $reason  why there is no capture, as a StoredMessage, for the page
     */
    private function __construct(
        public ?string $directory,
        public array $data,
        public ?string $reason,
    ) {}

    /**
     * A capture read from its folder.
     *
     * @param  array<string, mixed>  $data
     */
    public static function from(string $directory, array $data): self
    {
        return new self($directory, $data, null);
    }

    /**
     * No capture, and why.
     */
    public static function missing(string $reason): self
    {
        return new self(null, [], $reason);
    }

    /**
     * Whether there is a photo to show.
     */
    public function isAvailable(): bool
    {
        return $this->directory !== null && ($this->data['tiles'] ?? []) !== [];
    }

    /**
     * When the page was photographed (ISO 8601), or null.
     */
    public function capturedAt(): ?string
    {
        return $this->data['captured_at'] ?? null;
    }

    /**
     * One landmark of the page ("name", "about", "stars", "forks", "files", "commit", "languages", "readme"):
     * its text and its box, or null when the page does not show it.
     *
     * @return array{text: string, x: int, y: int, w: int, h: int}|null
     */
    public function landmark(string $key): ?array
    {
        $landmark = $this->data['landmarks'][$key] ?? null;

        return is_array($landmark) && isset($landmark['x'], $landmark['y'], $landmark['w'], $landmark['h']) ? $landmark : null;
    }

    /**
     * The README heading with this text (compared without letter case, emphasis or emoji), or null.
     *
     * @return array{level: int, text: string, x: int, y: int, w: int, h: int}|null
     */
    public function heading(?string $text): ?array
    {
        $needle = self::comparable((string) $text);

        if ($needle === '') {
            return null;
        }

        foreach ($this->data['headings'] ?? [] as $heading) {
            if (self::comparable($heading['text']) === $needle) {
                return $heading;
            }
        }

        return null;
    }

    /**
     * The headings of the README, in page order.
     *
     * @return list<array{level: int, text: string, x: int, y: int, w: int, h: int}>
     */
    public function headings(): array
    {
        return array_values($this->data['headings'] ?? []);
    }

    /**
     * Where a quote is on the page: the README block (paragraph, list item, table cell, code block) that
     * contains it — for a code block, the exact line. The whole quote is tried first; a quote that the
     * page breaks differently (a link, a line break) is found by its longest run of at least MIN_WORDS words.
     *
     * @return array{kind: string, text: string, x: int, y: int, w: int, h: int}|null
     */
    public function locate(?string $quote): ?array
    {
        $needle = self::comparable((string) $quote);
        $words = $needle === '' ? [] : explode(' ', $needle);

        if (count($words) === 0) {
            return null;
        }

        for ($length = count($words); $length >= min(self::MIN_WORDS, count($words)); $length--) {
            for ($offset = 0; $offset + $length <= count($words); $offset++) {
                $run = implode(' ', array_slice($words, $offset, $length));

                // A single short word proves nothing about where the quote is.
                if (mb_strlen($run) < 8) {
                    continue;
                }

                foreach ($this->data['blocks'] ?? [] as $block) {
                    if (! str_contains(self::comparable($block['text']), $run)) {
                        continue;
                    }

                    foreach ($block['lines'] ?? [] as $line) {
                        if (str_contains(self::comparable($line['text']), $run)) {
                            // A long line of code scrolls inside its block: the frame stays inside the block.
                            $right = min($line['x'] + $line['w'], $block['x'] + $block['w'] - 8);

                            return ['kind' => 'code', 'text' => $line['text'], 'x' => $line['x'], 'y' => $line['y'], 'w' => max(40, $right - $line['x']), 'h' => $line['h']];
                        }
                    }

                    return ['kind' => $block['kind'], 'text' => $block['text'], 'x' => $block['x'], 'y' => $block['y'], 'w' => $block['w'], 'h' => $block['h']];
                }
            }
        }

        return null;
    }

    /**
     * The first README image (or video poster) between a heading and the next heading of the same or a
     * higher level, or null. With no heading: the first image of the README.
     *
     * @param  array{level: int, y: int}|null  $heading
     * @return array{alt: string, x: int, y: int, w: int, h: int}|null
     */
    public function imageUnder(?array $heading): ?array
    {
        $from = $heading['y'] ?? 0;
        $until = PHP_INT_MAX;

        foreach ($this->headings() as $next) {
            if ($heading !== null && $next['y'] > $from && $next['level'] <= $heading['level']) {
                $until = $next['y'];
                break;
            }
        }

        foreach ($this->data['images'] ?? [] as $image) {
            if ($image['y'] >= $from && $image['y'] < $until) {
                return $image;
            }
        }

        return null;
    }

    /**
     * What the photo says, without where: the texts of the landmarks, the headings and the README blocks.
     * Two photos of an unchanged page give the same fingerprint even when an image loaded a little taller.
     */
    public function fingerprint(): string
    {
        if (! $this->isAvailable()) {
            return 'none';
        }

        return hash('sha256', json_encode([
            array_map(fn (mixed $landmark): ?string => is_array($landmark) ? ($landmark['text'] ?? '') : null, $this->data['landmarks'] ?? []),
            array_column($this->data['headings'] ?? [], 'text'),
            array_column($this->data['blocks'] ?? [], 'text'),
            count($this->data['images'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * What the composition needs to draw the page: the tiles (paths relative to the composition), the
     * sizes ("scale" = video pixels per CSS pixel of the page: the tiles are shown 1:1), the page's own
     * background color and the repository's images: the preview (and whether its owner made it), the
     * owner's avatar and the README's logo. $prefix is the folder the capture is copied to inside the
     * composition.
     *
     * @return array<string, mixed>|null
     */
    public function forComposition(string $prefix): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $file = fn (?array $image): ?string => is_string($image['file'] ?? null) && is_file("{$this->directory}/{$image['file']}")
            ? "{$prefix}/{$image['file']}"
            : null;

        return [
            'width' => (int) $this->data['viewport']['width'],
            'scale' => (float) $this->data['viewport']['scale'],
            'height' => (int) $this->data['page']['captured_height'],
            'background' => (string) ($this->data['page']['background'] ?? '#0d1117'),
            'tiles' => array_map(fn (array $tile): array => ['file' => "{$prefix}/{$tile['file']}", 'y' => (int) $tile['y'], 'height' => (int) $tile['height']], $this->data['tiles']),
            'social' => $file($this->data['social'] ?? null),
            'social_custom' => (bool) ($this->data['social']['custom'] ?? false),
            'avatar' => $file($this->data['avatar'] ?? null),
            'logo' => $file($this->data['logo'] ?? null),
        ];
    }

    /**
     * A text reduced to its words, for comparing a quote with the page: PresentationFacts::comparable()
     * without Markdown links, list marks, emoji and punctuation at the ends of words.
     */
    public static function comparable(string $text): string
    {
        // [text](url) and ![alt](url) → text: the page shows only the link's words.
        $text = preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[\p{Extended_Pictographic}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;
        $text = PresentationFacts::comparable($text);
        $text = preg_replace('/(?<=\s|^)[#>\-+•|]+(?=\s|$)/u', ' ', $text) ?? $text;

        return Str::squish($text);
    }
}
