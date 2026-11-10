<?php

namespace Database\Factories;

use App\Enums\RepositoryStatus;
use App\Models\Repository;
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
