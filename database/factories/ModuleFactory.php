<?php

namespace Database\Factories;

use App\Models\Module;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Module>
 */
class ModuleFactory extends Factory
{
    /**
     * Define the model's default state: a folder two levels deep, like "app/Models".
     * The path is unique, so two modules of the same repository never collide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = fake()->unique()->slug(1).'/'.fake()->slug(1);

        return [
            'repository_id' => Repository::factory(),
            'path' => $path,
            'name' => $path,
            'file_count' => fake()->numberBetween(1, 40),
            'line_count' => fake()->numberBetween(10, 4000),
        ];
    }
}
