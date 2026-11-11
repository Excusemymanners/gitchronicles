<?php

namespace Database\Factories;

use App\Enums\DiagramType;
use App\Models\DiagramVersion;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiagramVersion>
 */
class DiagramVersionFactory extends Factory
{
    /**
     * Define the model's default state: one version of a small layers view.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory()->ready(),
            'type' => DiagramType::Layers,
            'key' => '',
            'sha' => fake()->sha1(),
            'mermaid' => "flowchart TB\n  m1[\"app/Http\"] --> m2[\"app/Models\"]\n",
            'meta' => ['title' => 'Arhitectura pe straturi', 'caption' => 'Modulele grupate pe straturi.'],
        ];
    }
}
