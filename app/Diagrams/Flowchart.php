<?php

namespace App\Diagrams;

/**
 * Writes the Mermaid code of one flowchart, statement by statement, so the diagram builders never
 * glue Mermaid syntax by hand. Every label goes in already escaped (MermaidText::label()).
 *
 * The code starts with a small configuration block ("front matter") that every architecture
 * diagram shares: the ELK layout (layers and orthogonal edges, bundled with Mermaid 12) and
 * straight ("linear") edge segments. Copied into another Mermaid editor, the code looks the same.
 */
final class Flowchart
{
    /**
     * The configuration written above every diagram; extra ELK options go in place of {elk}.
     */
    private const FRONT_MATTER = <<<'YAML'
        ---
        config:
          layout: elk
        {elk}  flowchart:
            curve: linear
            wrappingWidth: 240
        ---
        YAML;

    /**
     * The statements, already indented.
     *
     * @var list<string>
     */
    private array $statements = [];

    /**
     * class name => the ids of the nodes and subgraphs that use it.
     *
     * @var array<string, list<string>>
     */
    private array $classAssignments = [];

    /**
     * class name => its CSS-like style, for example "fill:#1168bd,color:#ffffff".
     *
     * @var array<string, string>
     */
    private array $classDefinitions = [];

    /**
     * How deep we are inside subgraphs (for the indentation only).
     */
    private int $depth = 1;

    /**
     * @param  'TB'|'LR'  $direction  top to bottom, or left to right
     * @param  array<string, string>  $elkOptions  ELK layout options, for example ['nodePlacementStrategy' => 'SIMPLE']
     */
    public function __construct(
        private string $direction = 'TB',
        private array $elkOptions = [],
    ) {}

    /**
     * A box. $label is escaped already; $shape is "rect" (corners), "rounded", "cylinder" (a database),
     * or one of Mermaid's named shapes written with @{ }: "person", "browser".
     */
    public function node(string $id, string $label, ?string $class = null, string $shape = 'rect'): self
    {
        $statement = match ($shape) {
            'rect' => "{$id}[\"{$label}\"]",
            'rounded' => "{$id}(\"{$label}\")",
            'cylinder' => "{$id}[(\"{$label}\")]",
            default => "{$id}@{ shape: {$shape}, label: \"{$label}\" }",
        };

        return $this->add($statement, $id, $class);
    }

    /**
     * A technology logo in a rounded tile, with the label under it ("icon shape").
     * $icon is an Iconify name from the "logos" pack, for example "logos:laravel".
     */
    public function iconNode(string $id, string $icon, string $label, ?string $class = null): self
    {
        return $this->add("{$id}@{ icon: \"{$icon}\", form: \"rounded\", label: \"{$label}\", pos: \"b\", h: 44 }", $id, $class);
    }

    /**
     * Open a group of nodes with a title; close it with endGroup().
     */
    public function startGroup(string $id, string $title, ?string $class = null): self
    {
        $this->add("subgraph {$id}[\"{$title}\"]", $id, $class);
        $this->depth++;

        return $this;
    }

    /**
     * Close the last group opened with startGroup().
     */
    public function endGroup(): self
    {
        $this->depth--;

        return $this->add('end');
    }

    /**
     * An arrow from one node to another, with an optional (escaped) label.
     */
    public function edge(string $from, string $to, ?string $label = null): self
    {
        return $this->add($label === null ? "{$from} --> {$to}" : "{$from} -->|\"{$label}\"| {$to}");
    }

    /**
     * A link that is not drawn: it only tells the layout to put $to after $from.
     */
    public function invisibleLink(string $from, string $to): self
    {
        return $this->add("{$from} ~~~ {$to}");
    }

    /**
     * Define a style class, used by node(), iconNode() and startGroup().
     */
    public function classDef(string $name, string $style): self
    {
        $this->classDefinitions[$name] = $style;

        return $this;
    }

    /**
     * The whole Mermaid code: configuration, statements, the style classes that are used and their assignments.
     */
    public function toMermaid(): string
    {
        $elk = '';

        if ($this->elkOptions !== []) {
            $elk = "  elk:\n";

            foreach ($this->elkOptions as $option => $value) {
                $elk .= "    {$option}: {$value}\n";
            }
        }

        $lines = [str_replace('{elk}', $elk, self::FRONT_MATTER), "flowchart {$this->direction}", ...$this->statements];

        foreach (array_intersect_key($this->classDefinitions, $this->classAssignments) as $name => $style) {
            $lines[] = "  classDef {$name} {$style}";
        }

        foreach ($this->classAssignments as $name => $ids) {
            $lines[] = '  class '.implode(',', $ids)." {$name}";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Add one statement at the current depth and remember its style class.
     */
    private function add(string $statement, ?string $id = null, ?string $class = null): self
    {
        $this->statements[] = str_repeat('  ', $this->depth).$statement;

        if ($id !== null && $class !== null) {
            $this->classAssignments[$class][] = $id;
        }

        return $this;
    }
}
