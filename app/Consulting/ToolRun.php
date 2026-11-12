<?php

namespace App\Consulting;

use App\Enums\ScanToolStatus;

/**
 * What one tool did in one analysis: its state, version and duration, the problems it found (at most
 * the cap, the most serious first, with how many were left out), why it failed (a ScanToolException
 * reason code and its number) and a few details for the page (files or packages scanned, the languages
 * counted by scc).
 */
final readonly class ToolRun
{
    /**
     * @param  list<Finding>  $findings
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $key,
        public string $name,
        public ScanToolStatus $status,
        public ?string $version = null,
        public ?int $durationMs = null,
        public array $findings = [],
        public int $omittedCount = 0,
        public ?string $error = null,
        public ?int $errorValue = null,
        public array $details = [],
    ) {}

    /**
     * The tool's entry in consulting_reports.tools (the findings are saved as rows).
     *
     * @return array{name: string, status: string, version: string|null, duration_ms: int|null, finding_count: int, omitted_count: int, error: string|null, error_value: int|null, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'version' => $this->version,
            'duration_ms' => $this->durationMs,
            'finding_count' => count($this->findings),
            'omitted_count' => $this->omittedCount,
            'error' => $this->error,
            'error_value' => $this->errorValue,
            'details' => $this->details,
        ];
    }
}
