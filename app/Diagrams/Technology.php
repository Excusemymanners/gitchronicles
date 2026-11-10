<?php

namespace App\Diagrams;

use App\Enums\TechnologyRole;

/**
 * A technology found in a repository's files, with the file that proves it.
 */
final readonly class Technology
{
    /**
     * @param  string  $key  a stable id, the same for every repository: "laravel", "postgresql", ...
     * @param  string  $name  the name shown, with the version when the manifest gives it: "Laravel 13"
     * @param  string|null  $icon  the logo from the Iconify "logos" pack, for example "logos:laravel"
     * @param  string  $source  the file it was found in, relative to the repository: "composer.json"
     */
    public function __construct(
        public string $key,
        public string $name,
        public TechnologyRole $role,
        public ?string $icon,
        public string $source,
    ) {}

    /**
     * The same technology with a version added to its name ("Laravel" → "Laravel 13").
     */
    public function withVersion(?string $version): self
    {
        if ($version === null || $version === '') {
            return $this;
        }

        return new self($this->key, "{$this->name} {$version}", $this->role, $this->icon, $this->source);
    }

    /**
     * The shape saved in the diagram's meta and sent to the page.
     *
     * @return array{name: string, icon: string|null, source: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'icon' => $this->icon, 'source' => $this->source];
    }
}
