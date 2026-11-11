<?php

namespace Database\Factories;

use App\Models\WatchedRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedRepository>
 */
class WatchedRepositoryFactory extends Factory
{
    /**
     * Define the model's default state: a repository created a few months ago, first seen today.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_id' => fake()->unique()->numberBetween(1_000, 900_000_000),
            'full_name' => fake()->unique()->userName().'/'.fake()->slug(2),
            'description' => fake()->sentence(),
            'language' => fake()->randomElement(['PHP', 'TypeScript', 'Python', 'Rust', 'Go']),
            'topics' => [],
            'license' => 'MIT',
            'stars' => 100,
            'forks' => 10,
            'github_created_at' => now()->subDays(100),
            'github_pushed_at' => now()->subDay(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
