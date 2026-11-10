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
     * Indicate that the repository was cloned at a known commit.
     */
    public function cloned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepositoryStatus::Cloned,
            'last_sha' => fake()->sha1(),
        ]);
    }

    /**
     * Indicate that cloning the repository failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RepositoryStatus::Failed,
        ]);
    }
}
