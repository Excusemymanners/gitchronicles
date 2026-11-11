<?php

namespace Database\Factories;

use App\Enums\RepositoryStatus;
use App\Models\CodeChunk;
use App\Models\Commit;
use App\Models\Diagram;
use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Owner and name are lowercase words joined by a hyphen (e.g. "dolorem-ipsum"),
     * so the URL is always a valid canonical GitHub URL. The owner is unique, so
     * two repositories never share a URL (the "url" column is unique).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $owner = fake()->unique()->slug(2, false);
        $name = fake()->slug(2, false);

        return [
            'user_id' => User::factory(),
            'url' => "https://github.com/{$owner}/{$name}",
            'owner' => $owner,
            'name' => $name,
            'status' => RepositoryStatus::Pending,
            'last_sha' => null,
        ];
    }

    /**
     * Indicate that the pipeline finished: the repository was cloned at a known commit.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepositoryStatus::Ready,
            'default_branch' => 'main',
            'last_sha' => fake()->sha1(),
            'cloned_at' => now(),
        ]);
    }

    /**
     * Indicate that the code analysis ran: the repository has files, modules and dependencies.
     */
    public function analyzed(): static
    {
        return $this->state(fn (array $attributes) => [
            'analyzed_at' => now(),
        ]);
    }

    /**
     * Indicate that every step of the pipeline ran: the repository has every artifact RepositoryCompleteness
     * expects (a module with a PHP file, the current commit, the overview page, a diagram and a code chunk).
     */
    public function complete(): static
    {
        return $this->ready()->analyzed()->afterCreating(function (Repository $repository): void {
            $module = Module::factory()->for($repository)->create(['path' => 'app/Http', 'name' => 'app/Http']);
            $file = RepositoryFile::factory()->for($repository)->create(['module_id' => $module->id, 'path' => 'app/Http/Kernel.php']);

            Commit::factory()->for($repository)->create(['sha' => $repository->last_sha]);
            Document::factory()->for($repository)->create(['slug' => Document::OVERVIEW, 'title' => 'Despre proiect', 'source_sha' => $repository->last_sha]);
            Diagram::factory()->for($repository)->create(['source_sha' => $repository->last_sha]);
            CodeChunk::factory()->create(['repository_file_id' => $file->id]);
        });
    }

    /**
     * Indicate that the pipeline stopped with an error.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepositoryStatus::Failed,
            'error' => 'Nu am putut clona repository-ul. Încearcă din nou peste câteva minute.',
        ]);
    }
}
