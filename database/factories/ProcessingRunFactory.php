<?php

namespace Database\Factories;

use App\Enums\ProcessingMode;
use App\Enums\ProcessingRunStatus;
use App\Enums\ProcessingTrigger;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProcessingRun>
 */
class ProcessingRunFactory extends Factory
{
    /**
     * Define the model's default state: the first processing of a newly added repository,
     * still running.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'mode' => ProcessingMode::Initial,
            'trigger' => ProcessingTrigger::Add,
            'status' => ProcessingRunStatus::Running,
            'started_at' => now(),
        ];
    }

    /**
     * Indicate that the run ended with an error.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProcessingRunStatus::Failed,
            'finished_at' => now(),
            'duration_ms' => 1200,
            'error' => 'Nu am putut clona repository-ul. Încearcă din nou peste câteva minute.',
        ]);
    }
}
