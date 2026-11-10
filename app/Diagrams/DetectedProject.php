<?php

namespace App\Diagrams;

use App\Enums\TechnologyRole;

/**
 * One project inside a repository: a folder with its own manifest (composer.json, package.json,
 * pyproject.toml, requirements.txt or go.mod), the technologies found in it and its .env.example.
 * Most repositories have one project, in the root folder ($folder = '').
 */
final readonly class DetectedProject
{
    /**
     * @param  string  $folder  the folder, relative to the repository ('' = the root folder)
     * @param  list<Technology>  $technologies  in the order they were found, each key once
     * @param  array<string, string>  $environment  the settings of its .env.example (empty without one)
     * @param  bool  $hasQueueJobs  whether it has Laravel job classes (app/Jobs) that a queue worker runs
     */
    public function __construct(
        public string $folder,
        public array $technologies,
        public array $environment,
        public bool $hasQueueJobs,
    ) {}

    /**
     * The first technology with this role, or null.
     */
    public function first(TechnologyRole $role): ?Technology
    {
        return $this->withRole($role)[0] ?? null;
    }

    /**
     * Every technology with this role.
     *
     * @return list<Technology>
     */
    public function withRole(TechnologyRole $role): array
    {
        return array_values(array_filter($this->technologies, fn (Technology $technology): bool => $technology->role === $role));
    }

    /**
     * Whether the project uses the technology with this key.
     */
    public function uses(string $key): bool
    {
        foreach ($this->technologies as $technology) {
            if ($technology->key === $key) {
                return true;
            }
        }

        return false;
    }
}
