<?php

namespace Tests\Unit\Consulting;

use App\Enums\FindingSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every tool's own severity words and scores land on the same five steps.
 */
class FindingSeverityTest extends TestCase
{
    /**
     * @return array<string, array{string, FindingSeverity}>
     */
    public static function semgrepSeverities(): array
    {
        return [
            'critical' => ['CRITICAL', FindingSeverity::Critical],
            'error' => ['ERROR', FindingSeverity::High],
            'high' => ['high', FindingSeverity::High],
            'warning' => ['WARNING', FindingSeverity::Medium],
            'info' => ['INFO', FindingSeverity::Low],
            'unknown' => ['EXPERIMENT', FindingSeverity::Info],
        ];
    }

    #[DataProvider('semgrepSeverities')]
    public function test_semgrep_severities(string $severity, FindingSeverity $expected): void
    {
        $this->assertSame($expected, FindingSeverity::fromSemgrep($severity));
    }

    /**
     * @return array<string, array{float, FindingSeverity}>
     */
    public static function cvssScores(): array
    {
        return [
            '9.8' => [9.8, FindingSeverity::Critical],
            '9.0' => [9.0, FindingSeverity::Critical],
            '8.9' => [8.9, FindingSeverity::High],
            '7.0' => [7.0, FindingSeverity::High],
            '6.9' => [6.9, FindingSeverity::Medium],
            '4.0' => [4.0, FindingSeverity::Medium],
            '3.1' => [3.1, FindingSeverity::Low],
            '0' => [0.0, FindingSeverity::Info],
        ];
    }

    #[DataProvider('cvssScores')]
    public function test_cvss_scores_use_the_bands_of_the_specification(float $score, FindingSeverity $expected): void
    {
        $this->assertSame($expected, FindingSeverity::fromCvssScore($score));
    }

    public function test_advisory_labels_and_weights(): void
    {
        $this->assertSame(FindingSeverity::Medium, FindingSeverity::fromAdvisoryLabel('MODERATE'));
        $this->assertSame(FindingSeverity::Critical, FindingSeverity::fromAdvisoryLabel('critical'));
        $this->assertNull(FindingSeverity::fromAdvisoryLabel('UNKNOWN'));
        $this->assertSame([8, 4, 2, 1, 0], array_map(fn (FindingSeverity $severity): int => $severity->weight(), FindingSeverity::cases()));
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn (FindingSeverity $severity): int => $severity->rank(), FindingSeverity::cases()));
    }
}
