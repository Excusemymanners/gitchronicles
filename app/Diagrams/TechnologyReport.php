<?php

namespace App\Diagrams;

/**
 * Everything TechnologyDetector found in a repository: its projects (folders with a manifest)
 * and the technologies of the whole repository (Docker, docker-compose services, Kubernetes).
 */
final readonly class TechnologyReport
{
    /**
     * @param  list<DetectedProject>  $projects  the root folder first, then by path
     * @param  list<Technology>  $shared  found in Dockerfile, docker-compose or Kubernetes files
     */
    public function __construct(
        public array $projects,
        public array $shared,
    ) {}

    /**
     * Every technology found, each key once (the first place it was found), for the list on the page.
     *
     * @return list<Technology>
     */
    public function all(): array
    {
        $found = [];

        foreach ($this->projects as $project) {
            array_push($found, ...$project->technologies);
        }

        array_push($found, ...$this->shared);

        $technologies = [];

        foreach ($found as $technology) {
            $technologies[$technology->key] ??= $technology;
        }

        return array_values($technologies);
    }
}
