<?php

namespace Database\Factories;

use App\Models\CodeLink;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeLink>
 */
class CodeLinkFactory extends Factory
{
    /**
     * Define the model's default state: a file that starts git (a link outside the code).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_file_id' => RepositoryFile::factory(),
            'repository_id' => fn (array $attributes) => RepositoryFile::find($attributes['repository_file_id'])->repository_id,
            'line' => fake()->numberBetween(1, 200),
            'kind' => 'runs',
            'target_file_id' => null,
            'target' => 'tool:git',
            'excerpt' => "->run(['git', 'fetch'])",
        ];
    }
}
