<?php

namespace App\Media\Slides;

/**
 * One slide of a deck, before it becomes Markdown: what the slide says, in which layout, and the fuller
 * text for the speaker notes. Only the fields its layout uses are filled.
 *
 * Layouts: title, statement, bullets, cards, diagram, columns, code, facts, closing (see renderers/slides/theme.css).
 */
final readonly class Slide
{
    /**
     * @param  string  $id  a stable name ("what", "module:app/Http"), used to match the answer of the AI
     * @param  list<string>  $bullets  the bullets of "bullets", or the cards of "cards" as "Title: text"
     * @param  array{uri: string, width: int, height: int}|null  $diagram  the drawn diagram of the "diagram" layout
     * @param  list<string>  $code  the lines of the "code" layout
     * @param  list<array{value: string, label: string}>  $stats  the number cards of the "facts" layout
     * @param  list<array{name: string, detail: string, share: ?int}>  $rows  the rows of the "columns" and "facts" layouts (a language with its share, a most changed file, a link)
     * @param  list<string>  $tags  short labels (technologies)
     * @param  string|null  $source  where the slide's content comes from (a page of the documentation)
     */
    public function __construct(
        public string $id,
        public string $layout,
        public string $headline,
        public ?string $eyebrow = null,
        public ?string $lead = null,
        public array $bullets = [],
        public ?array $diagram = null,
        public ?string $caption = null,
        public array $code = [],
        public array $stats = [],
        public array $rows = [],
        public array $tags = [],
        public ?string $notes = null,
        public ?string $source = null,
    ) {}

    /**
     * A copy with some fields replaced.
     *
     * @param  array<string, mixed>  $changes  property name => new value
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /**
     * A copy with the last item taken away (the last bullet, code line, row, tag or stat), or null when
     * nothing more can be taken. The renderer uses it to shorten a slide that does not fit.
     */
    public function shorter(): ?self
    {
        foreach (['bullets', 'code', 'rows', 'tags', 'stats'] as $field) {
            $items = $this->{$field};

            if (count($items) > ($field === 'code' ? 1 : 2)) {
                return $this->with([$field => array_slice($items, 0, -1)]);
            }
        }

        return null;
    }
}
