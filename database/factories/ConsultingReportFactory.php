<?php

namespace Database\Factories;

use App\Enums\ConsultingStatus;
use App\Enums\ScanToolStatus;
use App\Models\ConsultingReport;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConsultingReport>
 */
class ConsultingReportFactory extends Factory
{
    /**
     * Define the model's default state: a report still waiting in the queue.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory()->ready(),
            'source_sha' => fake()->sha1(),
            'status' => ConsultingStatus::Pending,
        ];
    }

    /**
     * Indicate that the analysis finished: the three tools ran, with the numbers of a small project.
     * The findings themselves are made by ConsultingFindingFactory.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsultingStatus::Ready,
            'tools' => [
                'scc' => ['name' => 'scc', 'status' => ScanToolStatus::Ran->value, 'version' => '4.1.0', 'duration_ms' => 40, 'finding_count' => 0, 'omitted_count' => 0, 'error' => null, 'error_value' => null, 'details' => []],
                'osv-scanner' => ['name' => 'OSV-Scanner', 'status' => ScanToolStatus::Ran->value, 'version' => '2.7.0', 'duration_ms' => 900, 'finding_count' => 0, 'omitted_count' => 0, 'error' => null, 'error_value' => null, 'details' => ['packages_scanned' => 4, 'lockfiles' => ['composer.lock']]],
                'semgrep' => ['name' => 'Semgrep', 'status' => ScanToolStatus::Ran->value, 'version' => '1.182.1', 'duration_ms' => 2700, 'finding_count' => 0, 'omitted_count' => 0, 'error' => null, 'error_value' => null, 'details' => ['files_scanned' => 8, 'errors' => 0]],
            ],
            'code_statistics' => [
                'languages' => [
                    ['name' => 'PHP', 'files' => 3, 'lines' => 41, 'code' => 30, 'comments' => 0, 'blanks' => 11, 'complexity' => 2],
                ],
                'totals' => ['files' => 3, 'lines' => 41, 'code' => 30, 'comments' => 0, 'blanks' => 11, 'complexity' => 2],
            ],
            'counts' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'bugs' => 0, 'code_vulnerabilities' => 0, 'dependency_advisories' => 0, 'vulnerable_packages' => 0],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    /**
     * Indicate that the report also holds the version check (the "runtime" tool): one end-of-life PHP and one current Node.js.
     */
    public function withRuntimeVersions(): static
    {
        return $this->state(function (array $attributes): array {
            $row = ['kind' => 'runtime', 'is_range' => false, 'reason' => null, 'url' => 'https://endoflife.date/php', 'missing_security_releases' => null];
            $tools = $attributes['tools'] ?? [];
            $tools['runtime'] = [
                'name' => 'Runtime versions', 'status' => ScanToolStatus::Ran->value, 'version' => null, 'duration_ms' => 800, 'finding_count' => 1, 'omitted_count' => 0, 'error' => null, 'error_value' => null,
                'details' => [
                    'fetched_at' => '2026-11-12T10:00:00+00:00',
                    'sources' => ['endoflife.date', 'nodejs.org', 'php.net'],
                    'versions' => [
                        [...$row, 'product' => 'php', 'label' => 'PHP', 'declared' => '^7.4', 'version' => '7.4', 'is_range' => true, 'path' => 'composer.json', 'line' => 9, 'status' => 'end_of_life', 'release_line' => '7.4', 'supported_until' => '2022-11-28', 'newest' => '7.4.33', 'target' => '8.5', 'target_newest' => '8.5.12'],
                        [...$row, 'product' => 'nodejs', 'label' => 'Node.js', 'declared' => '26', 'version' => '26', 'path' => '.nvmrc', 'line' => 1, 'status' => 'current', 'release_line' => '26', 'supported_until' => '2029-04-30', 'newest' => '26.13.1'],
                    ],
                ],
            ];

            return ['tools' => $tools];
        });
    }

    /**
     * Indicate that the analysis stopped with an error.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ConsultingStatus::Failed,
            'error' => ConsultingReport::ERROR_NOT_INSTALLED,
            'finished_at' => now(),
        ]);
    }
}
