<?php

namespace Tests\Feature\Diagrams;

use App\Diagrams\Technology;
use App\Diagrams\TechnologyCatalog;
use App\Diagrams\TechnologyDetector;
use App\Diagrams\TechnologyReport;
use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The technologies found in a repository's manifests. Each test writes a few files into the clone
 * folder (on the fake disk) and records them as analyzed files, the way the analysis does.
 */
class TechnologyDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_the_language_and_the_framework_versions_of_composer_json_and_package_json(): void
    {
        $report = $this->detect([
            'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^13.17', 'inertiajs/inertia-laravel' => '^3.5', 'guzzlehttp/guzzle' => '^7.9']]),
            'package.json' => json_encode(['dependencies' => ['react' => '^19.3.1', 'left-pad' => '^1.0'], 'devDependencies' => ['typescript' => '^7.0.2', 'vite' => '^8.0.0', 'tailwindcss' => '^4.0.0']]),
        ]);

        $this->assertSame(['PHP ^8.3', 'Laravel 13', 'Inertia', 'React 19', 'TypeScript', 'Vite', 'Tailwind CSS'], $this->names($report->projects[0]->technologies));
        $this->assertSame(['composer.json', 'composer.json', 'composer.json', 'package.json', 'package.json', 'package.json', 'package.json'], array_map(fn (Technology $technology): string => $technology->source, $report->projects[0]->technologies));
    }

    public function test_takes_the_servers_and_services_from_env_example_and_skips_comments_and_local_drivers(): void
    {
        $report = $this->detect([
            'composer.json' => json_encode(['require' => ['php' => '^8.3']]),
            '.env.example' => "# DB_CONNECTION=mysql\nDB_CONNECTION=pgsql\nCACHE_STORE=redis\nQUEUE_CONNECTION=database\nSESSION_DRIVER=file\nMAIL_MAILER=smtp\nFILESYSTEM_DISK=s3\nREDIS_HOST=127.0.0.1\n",
        ]);

        $this->assertSame(['PHP ^8.3', 'PostgreSQL', 'Redis', 'Server de e-mail (SMTP)', 'Amazon S3'], $this->names($report->projects[0]->technologies));
        $this->assertSame('pgsql', $report->projects[0]->environment['DB_CONNECTION']);
    }

    public function test_reads_python_packages_only_from_the_dependency_lists(): void
    {
        $report = $this->detect([
            'pyproject.toml' => "[project]\nname = \"magazin\"\ndescription = \"un cache redis pentru django\"\nrequires-python = \">=3.11\"\ndependencies = [\n  \"django>=5.1\",\n  \"celery[redis]\",\n]\n",
            'worker/requirements.txt' => "# servicii\nfastapi==0.115.0\nanthropic>=0.40\n",
        ]);

        $this->assertSame(['Python >=3.11', 'Django', 'Celery'], $this->names($report->projects[0]->technologies));
        $this->assertSame('worker', $report->projects[1]->folder);
        $this->assertSame(['Python', 'FastAPI', 'Anthropic API (Claude)'], $this->names($report->projects[1]->technologies));
    }

    public function test_reads_the_go_version_and_the_required_modules_of_go_mod(): void
    {
        $report = $this->detect([
            'go.mod' => "module example.com/magazin\n\ngo 1.22\n\nrequire (\n\tgithub.com/gin-gonic/gin v1.10.0\n\tgithub.com/jackc/pgx/v5 v5.7.1\n)\n",
        ]);

        $this->assertSame(['Go 1.22', 'Gin', 'PostgreSQL'], $this->names($report->projects[0]->technologies));
    }

    public function test_finds_docker_compose_images_and_kubernetes_files(): void
    {
        $report = $this->detect([
            'docker-compose.yml' => "services:\n  db:\n    image: docker.io/library/postgres:16-alpine\n  cache:\n    image: \"bitnami/redis:7\"\n  mail:\n    image: axllent/mailpit\n",
            'k8s/deployment.yaml' => "spec:\n  containers:\n    - name: web\n      image: nginx:1.27\n",
        ]);

        $this->assertSame([], $report->projects);
        $this->assertSame(['Docker', 'PostgreSQL', 'Redis', 'Kubernetes', 'Nginx'], $this->names($report->shared));
        $this->assertSame('k8s/', $report->shared[3]->source);
    }

    public function test_ignores_manifests_in_tests_examples_and_deep_folders(): void
    {
        $report = $this->detect([
            'tests/Fixtures/app/composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0']]),
            'examples/todo/package.json' => json_encode(['dependencies' => ['vue' => '^3.5']]),
            'a/b/c/package.json' => json_encode(['dependencies' => ['express' => '^5.0']]),
        ]);

        $this->assertSame([], $report->projects);
    }

    public function test_a_laravel_project_with_job_classes_has_queue_jobs_unless_the_queue_is_sync(): void
    {
        $withQueue = $this->detect([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0']]),
            'app/Jobs/SendReport.php' => "<?php\n",
        ]);
        $withSyncQueue = $this->detect([
            'composer.json' => json_encode(['require' => ['laravel/framework' => '^13.0']]),
            '.env.example' => "QUEUE_CONNECTION=sync\n",
            'app/Jobs/SendReport.php' => "<?php\n",
        ]);

        $this->assertTrue($withQueue->projects[0]->hasQueueJobs);
        $this->assertFalse($withSyncQueue->projects[0]->hasQueueJobs);
    }

    public function test_every_logo_the_catalog_can_draw_is_bundled_for_the_browser(): void
    {
        $bundled = json_decode(File::get(resource_path('js/lib/diagram-icons.json')), true);
        $icons = array_filter(array_column(TechnologyCatalog::TECHNOLOGIES, 2));

        foreach ($icons as $icon) {
            $this->assertArrayHasKey(substr($icon, strlen('logos:')), $bundled['icons'], "{$icon} is missing from diagram-icons.json");
        }
        $this->assertSame('logos', $bundled['prefix']);
    }

    /**
     * Write the files into a new repository's clone, record them as analyzed and detect.
     *
     * @param  array<string, string>  $files  path => contents
     */
    private function detect(array $files): TechnologyReport
    {
        $repository = Repository::factory()->ready()->create();

        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$repository->clonePath()}/{$path}"));
            File::put("{$repository->clonePath()}/{$path}", $contents);
            RepositoryFile::factory()->for($repository)->create(['path' => $path, 'language' => null]);
        }

        return app(TechnologyDetector::class)->detect($repository);
    }

    /**
     * The names of the technologies, in order.
     *
     * @param  list<Technology>  $technologies
     * @return list<string>
     */
    private function names(array $technologies): array
    {
        return array_map(fn (Technology $technology): string => $technology->name, $technologies);
    }
}
