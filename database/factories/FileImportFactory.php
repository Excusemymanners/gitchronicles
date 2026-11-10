<?php

namespace Database\Factories;

use App\Models\FileImport;
use App\Models\RepositoryFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FileImport>
 */
class FileImportFactory extends Factory
{
    /**
     * Define the model's default state: an import of a library (no file of the repository).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_file_id' => RepositoryFile::factory(),
            'repository_id' => fn (array $attributes) => RepositoryFile::find($attributes['repository_file_id'])->repository_id,
            'target' => 'Illuminate\\Support\\Str',
            'target_file_id' => null,
            'is_external' => true,
        ];
    }
}
