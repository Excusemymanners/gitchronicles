<?php

namespace Tests\Feature\Consulting;

use App\Consulting\Runtime\Declaration;
use App\Consulting\Runtime\DeclarationReader;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesSupportData;
use Tests\TestCase;

/**
 * The runtime and framework versions found in a clone's manifests, with the file and line of each.
 */
class DeclarationReaderTest extends TestCase
{
    use FakesSupportData;
    use RefreshDatabase;

    public function test_composer_json_gives_php_from_require_and_from_the_platform_with_their_lines(): void
    {
        $found = $this->read(['composer.json' => "{\n  \"require\": {\n    \"php\": \"^8.2\"\n  },\n  \"config\": {\n    \"platform\": {\n      \"php\": \"8.1.4\"\n    }\n  }\n}\n"]);

        $this->assertSame(['php ^8.2 8.2 range composer.json:3', 'php 8.1.4 8.1.4 exact composer.json:7'], $found);
    }

    public function test_node_python_go_ruby_and_java_files(): void
    {
        $found = $this->read([
            'package.json' => "{\n  \"name\": \"x\",\n  \"engines\": {\n    \"node\": \">=18\"\n  }\n}\n",
            '.nvmrc' => "v20.11.0\n",
            '.python-version' => "3.11.4\n",
            'runtime.txt' => "python-3.9.18\n",
            'pyproject.toml' => "[project]\nname = \"x\"\nrequires-python = \">=3.8\"\n",
            'go.mod' => "module x\n\ngo 1.22.0\n",
            '.ruby-version' => "3.2.2\n",
            'Gemfile' => "source 'https://rubygems.org'\nruby \"~> 3.1\"\n",
            'pom.xml' => "<project>\n<properties>\n<java.version>1.8</java.version>\n</properties></project>\n",
            'build.gradle.kts' => "java {\n  toolchain { languageVersion.set(JavaLanguageVersion.of(17)) }\n}\n",
            'App/App.csproj' => "<Project>\n<PropertyGroup><TargetFramework>net6.0</TargetFramework></PropertyGroup></Project>\n",
        ]);

        $this->assertEqualsCanonicalizing([
            'nodejs >=18 18 range package.json:4',
            'nodejs v20.11.0 20.11.0 exact .nvmrc:1',
            'python 3.11.4 3.11.4 exact .python-version:1',
            'python 3.9.18 3.9.18 exact runtime.txt:1',
            'python >=3.8 3.8 range pyproject.toml:3',
            'go 1.22.0 1.22.0 range go.mod:3',
            'ruby 3.2.2 3.2.2 exact .ruby-version:1',
            'ruby ~> 3.1 3.1 range Gemfile:2',
            'java 1.8 8 exact pom.xml:3',
            'java 17 17 exact build.gradle.kts:2',
            'dotnet net6.0 6.0 exact App/App.csproj:2',
        ], $found);
    }

    public function test_dockerfiles_read_the_from_lines_and_skip_tags_that_are_not_versions(): void
    {
        $found = $this->read(['Dockerfile' => "FROM node:20.11.0-alpine AS build\nFROM php:8.2-fpm\nFROM nginx:1.27\nFROM python:latest\nFROM --platform=linux/amd64 golang:1.22 AS go\n"]);

        $this->assertSame([
            'nodejs node:20.11.0-alpine 20.11.0 exact Dockerfile:1',
            'php php:8.2-fpm 8.2 exact Dockerfile:2',
            'go golang:1.22 1.22 exact Dockerfile:5',
        ], $found);
    }

    public function test_frameworks_use_the_installed_version_of_the_lock_file_and_the_manifest_only_without_one(): void
    {
        $found = $this->read([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0']]),
            'composer.lock' => "{\n \"packages\": [\n  {\n   \"name\": \"laravel/framework\",\n   \"version\": \"v13.0.1\"\n  }\n ]\n}\n",
            'web/package.json' => json_encode(['dependencies' => ['next' => '^14.1.0', 'react' => '18.2.0']]),
            'requirements.txt' => "# deps\nDjango==5.2.1\n",
            'Gemfile.lock' => "GEM\n  specs:\n    rails (7.1.3)\n",
        ]);

        $this->assertEqualsCanonicalizing([
            'laravel v13.0.1 13.0.1 exact composer.lock:4',
            'nextjs ^14.1.0 14.1.0 range web/package.json:1',
            'react 18.2.0 18.2.0 exact web/package.json:1',
            'django 5.2.1 5.2.1 exact requirements.txt:2',
            'rails 7.1.3 7.1.3 exact Gemfile.lock:3',
        ], $found);
    }

    public function test_package_lock_gives_the_installed_framework_versions(): void
    {
        $found = $this->read([
            'package.json' => json_encode(['dependencies' => ['react' => '^18.0.0']]),
            'package-lock.json' => json_encode(['lockfileVersion' => 3, 'packages' => ['' => [], 'node_modules/react' => ['version' => '18.3.1']]], JSON_PRETTY_PRINT),
        ]);

        $this->assertCount(1, $found);
        $this->assertStringStartsWith('react 18.3.1 18.3.1 exact package-lock.json:', $found[0]);
    }

    public function test_test_folders_and_unreadable_values_are_ignored(): void
    {
        $found = $this->read([
            'tests/fixtures/composer.json' => json_encode(['require' => ['php' => '^5.6']]),
            '.nvmrc' => "lts/*\n",
            'composer.json' => '{ not json',
        ]);

        $this->assertSame([], $found);
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    private function read(array $files): array
    {
        $repository = Repository::factory()->ready()->create();
        $this->writeClone($repository, $files);

        return array_map(
            fn (Declaration $declaration): string => implode(' ', [$declaration->product, $declaration->declared, $declaration->version, $declaration->isRange ? 'range' : 'exact', "{$declaration->path}:{$declaration->line}"]),
            app(DeclarationReader::class)->read($repository),
        );
    }
}
