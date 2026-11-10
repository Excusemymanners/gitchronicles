<?php

namespace Tests\Feature\Search;

use App\Models\CodeChunk;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Search\LikeCodeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portable search (plain LIKE queries) runs here on SQLite: its SQL is the same on any database.
 */
class LikeCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_a_chunk_named_after_the_word_above_one_that_only_mentions_it_and_leaves_out_the_rest(): void
    {
        $repository = Repository::factory()->create();
        $mailer = $this->chunk($repository, 'app/Support/Mailer.php', '// After the login, send an email.');
        $controller = $this->chunk($repository, 'app/Http/Controllers/Auth/LoginController.php', 'return redirect()->intended();', 'LoginController.store');
        $this->chunk($repository, 'README.md', '# Magazin');

        $found = (new LikeCodeSearch)->search($repository, ['login'], 8);

        $this->assertSame([$controller->id, $mailer->id], $found->pluck('id')->all());
    }

    public function test_searches_only_the_given_repository_and_paths(): void
    {
        $repository = Repository::factory()->create();
        $inFolder = $this->chunk($repository, 'app/Http/Controllers/HomeController.php', 'function login() {}');
        $this->chunk($repository, 'app/Models/User.php', 'function login() {}');
        $this->chunk(Repository::factory()->create(), 'app/Http/Controllers/HomeController.php', 'function login() {}');

        $found = (new LikeCodeSearch)->search($repository, ['login'], 8, ['app/Http/']);

        $this->assertSame([$inFolder->id], $found->pluck('id')->all());
    }

    public function test_treats_like_wildcards_in_the_terms_as_plain_characters(): void
    {
        $repository = Repository::factory()->create();
        $percent = $this->chunk($repository, 'app/Discount.php', 'return 10%;');
        $this->chunk($repository, 'app/Login.php', 'login');

        $this->assertSame([$percent->id], (new LikeCodeSearch)->search($repository, ['%'], 8)->pluck('id')->all());
        $this->assertTrue((new LikeCodeSearch)->search($repository, ['_'], 8)->isEmpty());
    }

    public function test_returns_at_most_the_limit_and_nothing_without_terms(): void
    {
        $repository = Repository::factory()->create();
        foreach (range(1, 4) as $number) {
            $this->chunk($repository, "app/Login{$number}.php", 'login');
        }

        $this->assertCount(3, (new LikeCodeSearch)->search($repository, ['login'], 3));
        $this->assertTrue((new LikeCodeSearch)->search($repository, [], 8)->isEmpty());
    }

    /**
     * A chunk of a new file of the repository.
     */
    private function chunk(Repository $repository, string $path, string $content, ?string $symbolName = null): CodeChunk
    {
        $file = RepositoryFile::factory()->for($repository)->create(['path' => $path]);

        return CodeChunk::factory()->for($file, 'repositoryFile')->create(['content' => $content, 'symbol_name' => $symbolName]);
    }
}
