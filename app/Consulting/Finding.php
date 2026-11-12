<?php

namespace App\Consulting;

use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use App\Media\RomanianText;
use Illuminate\Support\Str;

/**
 * One problem read from a tool's output, before it is saved as a consulting_findings row.
 *
 * Every text here comes from the analyzed repository or from a tool that read it, so toAttributes()
 * removes control characters and cuts each one to the length of its column: a hostile repository cannot
 * put a megabyte of text, or invisible characters, into the database or the page.
 */
final readonly class Finding
{
    /**
     * The longest message kept, in characters (the advisories of OSV can be several pages of Markdown).
     */
    public const MESSAGE_LIMIT = 1000;

    public function __construct(
        public string $tool,
        public FindingKind $kind,
        public FindingSeverity $severity,
        public ?string $originalSeverity,
        public string $ruleId,
        public string $title,
        public string $message,
        public ?string $path = null,
        public ?int $startLine = null,
        public ?int $endLine = null,
        public ?string $package = null,
        public ?string $ecosystem = null,
        public ?string $installedVersion = null,
        public ?string $fixedVersion = null,
        public ?string $advisoryId = null,
        public ?string $advisoryUrl = null,
    ) {}

    /**
     * The attributes of the consulting_findings row, cleaned and cut to fit.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'tool' => $this->tool,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'original_severity' => self::short($this->originalSeverity, 50),
            'rule_id' => self::short($this->ruleId, 200) ?? '',
            'title' => self::short($this->title, 255) ?? '',
            'message' => self::short($this->message, self::MESSAGE_LIMIT) ?? '',
            'path' => self::short($this->path, 500),
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'package' => self::short($this->package, 255),
            'ecosystem' => self::short($this->ecosystem, 50),
            'installed_version' => self::short($this->installedVersion, 100),
            'fixed_version' => self::short($this->fixedVersion, 100),
            'advisory_id' => self::short($this->advisoryId, 100),
            'advisory_url' => self::short($this->advisoryUrl, 500),
        ];
    }

    /**
     * A text on one line, without control characters, cut to $limit characters (null stays null).
     */
    private static function short(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }

        return Str::limit(RomanianText::clean($text), $limit - 1, '…');
    }
}
