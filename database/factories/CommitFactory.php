<?php

namespace Database\Factories;

use App\Models\Commit;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commit>
 */
class CommitFactory extends Factory
{
    /**
     * Define the model's default state: an ordinary commit (one parent) that changed one file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'sha' => fake()->unique()->sha1(),
            'parent_sha' => fake()->sha1(),
            'is_merge' => false,
            'author_name' => fake()->name(),
            'authored_at' => fake()->dateTimeBetween('-1 year'),
            'subject' => fake()->sentence(),
            'files_changed' => 1,
            'insertions' => fake()->numberBetween(0, 200),
            'deletions' => fake()->numberBetween(0, 200),
        ];
    }

    /**
     * Indicate that the commit merges a branch: two parents, no counters (git shows no changes).
     */
    public function merge(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_merge' => true,
            'subject' => 'Merge branch '.fake()->slug(2),
            'files_changed' => null,
            'insertions' => null,
            'deletions' => null,
        ]);
    }
}
