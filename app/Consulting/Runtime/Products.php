<?php

namespace App\Consulting\Runtime;

/**
 * The runtimes and frameworks the version check knows: our key, the name shown on the page and the product
 * name of endoflife.date (https://endoflife.date/api/v1/products/{slug}/). Only this name ever leaves the
 * server — nothing from the repository.
 *
 * Rust and the other languages without a published support policy are left out: there is nothing to compare with.
 */
final class Products
{
    /**
     * @return array<string, array{label: string, slug: string, kind: 'runtime'|'framework'}>
     */
    public static function all(): array
    {
        return [
            'php' => ['label' => 'PHP', 'slug' => 'php', 'kind' => 'runtime'],
            'nodejs' => ['label' => 'Node.js', 'slug' => 'nodejs', 'kind' => 'runtime'],
            'python' => ['label' => 'Python', 'slug' => 'python', 'kind' => 'runtime'],
            'go' => ['label' => 'Go', 'slug' => 'go', 'kind' => 'runtime'],
            'ruby' => ['label' => 'Ruby', 'slug' => 'ruby', 'kind' => 'runtime'],
            'java' => ['label' => 'Java', 'slug' => 'eclipse-temurin', 'kind' => 'runtime'],
            'dotnet' => ['label' => '.NET', 'slug' => 'dotnet', 'kind' => 'runtime'],
            'laravel' => ['label' => 'Laravel', 'slug' => 'laravel', 'kind' => 'framework'],
            'symfony' => ['label' => 'Symfony', 'slug' => 'symfony', 'kind' => 'framework'],
            'django' => ['label' => 'Django', 'slug' => 'django', 'kind' => 'framework'],
            'rails' => ['label' => 'Ruby on Rails', 'slug' => 'rails', 'kind' => 'framework'],
            'nextjs' => ['label' => 'Next.js', 'slug' => 'nextjs', 'kind' => 'framework'],
            'angular' => ['label' => 'Angular', 'slug' => 'angular', 'kind' => 'framework'],
            'vue' => ['label' => 'Vue', 'slug' => 'vue', 'kind' => 'framework'],
            'react' => ['label' => 'React', 'slug' => 'react', 'kind' => 'framework'],
            'express' => ['label' => 'Express', 'slug' => 'express', 'kind' => 'framework'],
            'nuxt' => ['label' => 'Nuxt', 'slug' => 'nuxt', 'kind' => 'framework'],
            'nestjs' => ['label' => 'NestJS', 'slug' => 'nestjs', 'kind' => 'framework'],
        ];
    }

    /**
     * The package names of the JavaScript frameworks, in package.json and package-lock.json.
     *
     * @return array<string, string>
     */
    public static function npmPackages(): array
    {
        return ['next' => 'nextjs', '@angular/core' => 'angular', 'vue' => 'vue', 'react' => 'react', 'express' => 'express', 'nuxt' => 'nuxt', '@nestjs/core' => 'nestjs'];
    }
}
