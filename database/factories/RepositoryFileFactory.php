<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryFile>
 */
class RepositoryFileFactory extends Factory
{
    /**
     * Define the model's default state: a PHP file without a module.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'module_id' => null,
            'path' => 'app/'.fake()->unique()->word().'.php',
            'language' => 'PHP',
            'lines' => fake()->numberBetween(1, 500),
            'size_bytes' => fake()->numberBetween(10, 20000),
            'blob_sha' => fake()->sha1(),
        ];
    }
}
