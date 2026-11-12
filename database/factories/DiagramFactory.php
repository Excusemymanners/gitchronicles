<?php

namespace Database\Factories;

use App\Enums\DiagramType;
use App\Models\Diagram;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diagram>
 */
class DiagramFactory extends Factory
{
    /**
     * Define the model's default state: a small layers view of a repository.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory()->ready(),
            'type' => DiagramType::Layers,
            'key' => '',
            'mermaid' => "flowchart TB\n  m1[\"app/Http\"] --> m2[\"app/Models\"]\n",
            'meta' => ['title' => 'Arhitectura pe straturi', 'caption' => 'Modulele grupate pe straturi.'],
            'source_sha' => fake()->sha1(),
        ];
    }

    /**
     * Indicate that the diagram is a „Cum funcționează” flow written by Claude.
     */
    public function flow(string $key = 'adaugarea-unui-repository'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DiagramType::Flow,
            'key' => $key,
            'mermaid' => "flowchart TD\n  A[\"The form\"] --> B[\"RepositoryController\"]\n",
            'meta' => [
                'title' => 'Adding a repository',
                'explanation' => 'The user sends the address, and the controller creates the repository.',
                'content_language' => 'en',
                'sources' => [['path' => 'app/Http/Controllers/RepositoryController.php', 'start_line' => 10, 'end_line' => 20]],
            ],
        ]);
    }
}
