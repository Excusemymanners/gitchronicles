<?php

namespace Database\Factories;

use App\Models\CodeChunk;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CodeChunk>
 */
class CodeChunkFactory extends Factory
{
    /**
     * Define the model's default state: three lines of a file, in the file's repository.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startLine = fake()->numberBetween(1, 100);

        return [
            'repository_file_id' => RepositoryFile::factory(),
            'repository_id' => fn (array $attributes) => RepositoryFile::find($attributes['repository_file_id'])->repository_id,
            'start_line' => $startLine,
            'end_line' => $startLine + 2,
            'symbol_name' => null,
            'content' => "<?php\n\n// ".fake()->sentence(),
        ];
    }
}
