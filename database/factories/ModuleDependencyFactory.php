<?php

namespace Database\Factories;

use App\Models\Module;
use App\Models\ModuleDependency;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleDependency>
 */
class ModuleDependencyFactory extends Factory
{
    /**
     * Define the model's default state: two modules of the same repository.
     * Use ->recycle($repository) or ->for() with existing modules in tests.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'from_module_id' => fn (array $attributes) => Module::factory()->create(['repository_id' => $attributes['repository_id']]),
            'to_module_id' => fn (array $attributes) => Module::factory()->create(['repository_id' => $attributes['repository_id']]),
            'weight' => fake()->numberBetween(1, 20),
        ];
    }
}
