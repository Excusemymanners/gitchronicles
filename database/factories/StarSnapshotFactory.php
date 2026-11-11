<?php

namespace Database\Factories;

use App\Models\StarSnapshot;
use App\Models\WatchedRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StarSnapshot>
 */
class StarSnapshotFactory extends Factory
{
    /**
     * Define the model's default state: today's stars of a watched repository.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'watched_repository_id' => WatchedRepository::factory(),
            'captured_on' => now()->toDateString(),
            'stars' => 100,
            'forks' => 10,
        ];
    }
}
