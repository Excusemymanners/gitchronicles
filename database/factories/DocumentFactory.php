<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\Module;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state: a module page written from the code analysis (no AI).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = fake()->unique()->slug(1).'/'.fake()->slug(1);

        return [
            'repository_id' => Repository::factory(),
            'module_id' => null,
            'slug' => str_replace('/', '-', $path),
            'title' => $path,
            'markdown' => "# {$path}\n\n## Rol\n\n".fake()->sentence(),
            'source_sha' => fake()->sha1(),
            'input_hash' => hash('sha256', fake()->sentence()),
            'writer' => Document::WRITER_TEMPLATE,
            'generated_at' => now(),
            'is_stale' => false,
        ];
    }

    /**
     * Indicate that the page documents the given module (same repository, slug and title from its path).
     */
    public function forModule(Module $module): static
    {
        return $this->state(fn (array $attributes) => [
            'repository_id' => $module->repository_id,
            'module_id' => $module->id,
            'slug' => str_replace('/', '-', $module->path),
            'title' => $module->path,
        ]);
    }

    /**
     * Indicate that the page is the overview („Despre proiect”).
     */
    public function overview(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Document::OVERVIEW,
            'title' => 'Overview',
            'markdown' => "# Despre proiect\n\n## Ce face proiectul\n\nUn magazin mic.\n\n## Tehnologii\n\n- PHP\n\n## Arhitectură\n\nTrei module.",
        ]);
    }

    /**
     * Indicate that the page is the generated README.
     */
    public function readme(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => Document::README,
            'title' => 'Generated README',
            'markdown' => "# Magazin\n\nUn magazin mic.\n\n## Instalare\n\n```bash\ncomposer install\n```\n",
        ]);
    }

    /**
     * Indicate that Claude wrote the page through the subscription (the Claude Code CLI).
     */
    public function writtenByAi(): static
    {
        return $this->state(fn (array $attributes) => [
            'writer' => Document::WRITER_AI,
            'model' => 'claude-opus-5-5',
            'ai_driver' => 'claude-code',
            'input_tokens' => 4200,
            'output_tokens' => 1300,
            'cost_usd_equivalent' => 0.0525,
        ]);
    }

    /**
     * Indicate that Claude failed and the page shows the text written from the analysis instead.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_stale' => true,
            'stale_reason' => 'Claude nu a putut scrie această pagină: Limita abonamentului Claude a fost atinsă; încearcă mai târziu.',
        ]);
    }
}
