<?php

namespace Tests\Feature\Search;

use App\Models\CodeChunk;
use App\Models\Repository;
use App\Models\RepositoryFile;
use App\Search\SqliteCodeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqliteCodeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_a_chunk_named_after_the_word_above_one_that_only_mentions_it_and_leaves_out_the_rest(): void
    {
        $repository = Repository::factory()->create();
        $controller = $this->chunk($repository, 'app/Http/Controllers/Auth/LoginController.php', 'return redirect()->intended();', 'LoginController.store');
        $mailer = $this->chunk($repository, 'app/Support/Mailer.php', '// After the login, send an email.');
        $this->chunk($repository, 'README.md', '# Magazin');
        (new SqliteCodeSearch)->rebuild($repository);

        $found = (new SqliteCodeSearch)->search($repository, ['login'], 8);

        $this->assertSame([$controller->id, $mailer->id], $found->pluck('id')->all());
        $this->assertSame('app/Http/Controllers/Auth/LoginController.php', $found->first()->repositoryFile->path);
    }

    public function test_matches_words_by_prefix_inside_identifiers_without_caring_about_case_or_diacritics(): void
    {
        $repository = Repository::factory()->create();
        $identifier = $this->chunk($repository, 'app/Http/Kernel.php', 'class HandleInertiaRequests extends Middleware');
        $romanian = $this->chunk($repository, 'docs/plan.md', 'AUTENTIFICĂRII și parolele');
        (new SqliteCodeSearch)->rebuild($repository);

        $this->assertSame([$identifier->id], (new SqliteCodeSearch)->search($repository, ['inertia'], 8)->pluck('id')->all());
        $this->assertSame([$identifier->id], (new SqliteCodeSearch)->search($repository, ['request'], 8)->pluck('id')->all());
        $this->assertSame([$romanian->id], (new SqliteCodeSearch)->search($repository, ['autentific'], 8)->pluck('id')->all());
    }

    public function test_searches_only_the_given_repository_and_paths(): void
    {
        $repository = Repository::factory()->create();
        $inFolder = $this->chunk($repository, 'app/Http/Controllers/HomeController.php', 'function login() {}');
        $file = $this->chunk($repository, 'routes/web.php', "Route::get('/login');");
        $this->chunk($repository, 'app/Models/User.php', 'function login() {}');
        $this->chunk($otherRepository = Repository::factory()->create(), 'app/Http/Controllers/HomeController.php', 'function login() {}');
        (new SqliteCodeSearch)->rebuild($repository);
        (new SqliteCodeSearch)->rebuild($otherRepository);

        $found = (new SqliteCodeSearch)->search($repository, ['login'], 8, ['app/Http/', 'routes/web.php']);

        $this->assertEqualsCanonicalizing([$inFolder->id, $file->id], $found->pluck('id')->all());
    }

    public function test_returns_at_most_the_limit(): void
    {
        $repository = Repository::factory()->create();
        foreach (range(1, 4) as $number) {
            $this->chunk($repository, "app/Login{$number}.php", 'login');
        }
        (new SqliteCodeSearch)->rebuild($repository);

        $this->assertCount(3, (new SqliteCodeSearch)->search($repository, ['login'], 3));
    }

    public function test_returns_nothing_without_terms(): void
    {
        $repository = Repository::factory()->create();
        $this->chunk($repository, 'app/Login.php', 'login');
        (new SqliteCodeSearch)->rebuild($repository);

        $this->assertTrue((new SqliteCodeSearch)->search($repository, [], 8)->isEmpty());
    }

    public function test_treats_search_syntax_in_the_terms_as_plain_words(): void
    {
        $repository = Repository::factory()->create();
        $chunk = $this->chunk($repository, 'app/Login.php', 'not or and near login');
        (new SqliteCodeSearch)->rebuild($repository);

        $found = (new SqliteCodeSearch)->search($repository, ['not', 'or', 'and', 'near', '"login', 'login*'], 8);

        $this->assertSame([$chunk->id], $found->pluck('id')->all());
    }

    public function test_rebuilding_replaces_the_index_of_the_repository(): void
    {
        $repository = Repository::factory()->create();
        $old = $this->chunk($repository, 'app/Login.php', 'login');
        (new SqliteCodeSearch)->rebuild($repository);
        $old->delete();
        $new = $this->chunk($repository, 'app/Logout.php', 'logout');

        (new SqliteCodeSearch)->rebuild($repository);

        $this->assertSame([$new->id], DB::table('code_chunks_fts')->where('repository_id', $repository->id)->pluck('rowid')->all());
    }

    public function test_deleting_a_repository_deletes_its_index_and_keeps_the_others(): void
    {
        $repository = Repository::factory()->create();
        $this->chunk($repository, 'app/Login.php', 'login');
        $otherChunk = $this->chunk($otherRepository = Repository::factory()->create(), 'app/Login.php', 'login');
        (new SqliteCodeSearch)->rebuild($repository);
        (new SqliteCodeSearch)->rebuild($otherRepository);

        $repository->delete();

        $this->assertSame([$otherChunk->id], DB::table('code_chunks_fts')->pluck('rowid')->all());
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
