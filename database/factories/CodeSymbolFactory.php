<?php

namespace Database\Factories;

use App\Models\CodeSymbol;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeSymbol>
 */
class CodeSymbolFactory extends Factory
{
    /**
     * Define the model's default state: a class in a file of the same repository.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startLine = fake()->numberBetween(1, 100);

        return [
            'repository_file_id' => RepositoryFile::factory(),
            'repository_id' => fn (array $attributes) => RepositoryFile::find($attributes['repository_file_id'])->repository_id,
            'kind' => 'class',
            'name' => fake()->word(),
            'start_line' => $startLine,
            'end_line' => $startLine + fake()->numberBetween(0, 50),
        ];
    }
}
