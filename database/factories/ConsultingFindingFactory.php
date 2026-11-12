<?php

namespace Database\Factories;

use App\Enums\FindingKind;
use App\Enums\FindingSeverity;
use App\Models\ConsultingFinding;
use App\Models\ConsultingReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultingFinding>
 */
class ConsultingFindingFactory extends Factory
{
    /**
     * Define the model's default state: a problem Semgrep found in a PHP file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consulting_report_id' => ConsultingReport::factory()->ready(),
            'tool' => 'semgrep',
            'kind' => FindingKind::Vulnerability,
            'severity' => FindingSeverity::High,
            'original_severity' => 'ERROR',
            'rule_id' => 'php-shell-command',
            'title' => 'Comandă de shell dintr-o variabilă',
            'message' => 'O comandă de shell e construită dintr-o variabilă.',
            'path' => 'app/Http/ReportController.php',
            'start_line' => 14,
            'end_line' => 14,
        ];
    }

    /**
     * Indicate that the problem is a bug, not a vulnerability.
     */
    public function bug(): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => FindingKind::Bug,
            'severity' => FindingSeverity::Medium,
            'original_severity' => 'WARNING',
            'rule_id' => 'laravel-env-outside-config',
            'title' => 'env() în afara config/',
            'message' => 'env() în afara folderului config/ întoarce null după config:cache.',
        ]);
    }

    /**
     * Indicate that the problem is a vulnerable dependency found by OSV-Scanner.
     */
    public function dependency(): static
    {
        return $this->state(fn (array $attributes) => [
            'tool' => 'osv-scanner',
            'kind' => FindingKind::Vulnerability,
            'severity' => FindingSeverity::High,
            'original_severity' => 'CVSS 7.4',
            'rule_id' => 'GHSA-p6mc-m468-83gw',
            'title' => 'Prototype Pollution in lodash',
            'message' => 'Versions of lodash prior to 4.17.19 are vulnerable to Prototype Pollution.',
            'path' => 'package-lock.json',
            'start_line' => null,
            'end_line' => null,
            'package' => 'lodash',
            'ecosystem' => 'npm',
            'installed_version' => '4.17.15',
            'fixed_version' => '4.17.19',
            'advisory_id' => 'GHSA-p6mc-m468-83gw',
            'advisory_url' => 'https://osv.dev/vulnerability/GHSA-p6mc-m468-83gw',
        ]);
    }
}
