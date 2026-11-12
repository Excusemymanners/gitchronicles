<?php

namespace App\Enums;

/**
 * How serious a problem of the „Consultanță” report is, on one scale for every tool (each tool has its own
 * words: Semgrep says ERROR/WARNING/INFO, OSV gives a CVSS score or GitHub's HIGH/MODERATE/LOW). The tool's
 * own value is kept next to it (consulting_findings.original_severity).
 */
enum FindingSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Info = 'info';

    /**
     * The label shown on the page.
     */
    public function label(): string
    {
        return __('enums.finding_severity.'.$this->value);
    }

    /**
     * How much a problem of this severity counts in the risk score: each step weighs twice the one below,
     * and an informative note does not count.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 8,
            self::High => 4,
            self::Medium => 2,
            self::Low => 1,
            self::Info => 0,
        };
    }

    /**
     * The position when sorting, the most serious first (0).
     */
    public function rank(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /**
     * A Semgrep rule's severity. Semgrep's old names map to its new ones: ERROR = HIGH, WARNING = MEDIUM,
     * INFO = LOW (semgrep.dev/docs, "Rule severity"); anything unknown is informative.
     */
    public static function fromSemgrep(string $severity): self
    {
        return match (strtoupper($severity)) {
            'CRITICAL' => self::Critical,
            'ERROR', 'HIGH' => self::High,
            'WARNING', 'MEDIUM' => self::Medium,
            'INFO', 'LOW' => self::Low,
            default => self::Info,
        };
    }

    /**
     * A CVSS base score (0–10) in the bands of the CVSS specification: 9.0+ critical, 7.0+ high,
     * 4.0+ medium, above 0 low.
     */
    public static function fromCvssScore(float $score): self
    {
        return match (true) {
            $score >= 9.0 => self::Critical,
            $score >= 7.0 => self::High,
            $score >= 4.0 => self::Medium,
            $score > 0.0 => self::Low,
            default => self::Info,
        };
    }

    /**
     * The severity word of an advisory database (GitHub uses MODERATE for medium), or null when unknown.
     */
    public static function fromAdvisoryLabel(string $label): ?self
    {
        return match (strtoupper($label)) {
            'CRITICAL' => self::Critical,
            'HIGH' => self::High,
            'MODERATE', 'MEDIUM' => self::Medium,
            'LOW' => self::Low,
            default => null,
        };
    }
}
