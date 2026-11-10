<?php

namespace Database\Factories;

use App\Models\Commit;
use App\Models\CommitFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommitFile>
 */
class CommitFileFactory extends Factory
{
    /**
     * Define the model's default state: a PHP file with a few changed lines.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'commit_id' => Commit::factory(),
            'path' => 'app/'.fake()->word().'.php',
            'insertions' => fake()->numberBetween(0, 50),
            'deletions' => fake()->numberBetween(0, 50),
        ];
    }
}
