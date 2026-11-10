<?php

namespace Tests\Feature\Search;

use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Search\MentionedPaths;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MentionedPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finds_a_folder_with_its_real_spelling_and_removes_it_from_the_question(): void
    {
        $repository = $this->repositoryWithFiles(['app/Http/Controllers/HomeController.php', 'app/Models/User.php']);

        $mentioned = MentionedPaths::in($repository, 'Ce face modulul app/http?');

        $this->assertSame(['paths' => ['app/Http/'], 'rest' => 'Ce face modulul  ?'], $mentioned);
    }

    public function test_finds_a_file_by_its_path_or_by_its_name(): void
    {
        $repository = $this->repositoryWithFiles(['routes/web.php', 'app/Http/Controllers/Auth/LoginController.php', 'app/Http/Controllers/HomeController.php']);

        $this->assertSame(['routes/web.php'], MentionedPaths::in($repository, 'Ce rute are routes/web.php.')['paths']);
        $this->assertSame(['app/Http/Controllers/Auth/LoginController.php'], MentionedPaths::in($repository, 'Ce face LoginController.php?')['paths']);
    }

    public function test_ignores_paths_and_names_the_repository_does_not_have(): void
    {
        $repository = $this->repositoryWithFiles(['app/Models/User.php']);

        $mentioned = MentionedPaths::in($repository, 'Merge cu Node.js și src/lib?');

        $this->assertSame(['paths' => [], 'rest' => 'Merge cu Node.js și src/lib?'], $mentioned);
    }

    public function test_does_not_look_in_other_repositories(): void
    {
        $this->repositoryWithFiles(['app/Http/Kernel.php']);
        $repository = $this->repositoryWithFiles(['README.md']);

        $this->assertSame([], MentionedPaths::in($repository, 'Ce face app/Http?')['paths']);
    }

    /**
     * A repository whose analysis saved these files.
     *
     * @param  list<string>  $paths
     */
    private function repositoryWithFiles(array $paths): Repository
    {
        $repository = Repository::factory()->create();

        foreach ($paths as $path) {
            RepositoryFile::factory()->for($repository)->create(['path' => $path]);
        }

        return $repository;
    }
}
