<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingTrigger;
use App\Exceptions\RepositoryProcessingException;
use App\Jobs\ProcessRepository;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * Hostile repositories, built here as small local git repositories: the clone must refuse them before the
 * files reach the disk, say why in a stored message, and leave nothing behind.
 */
class HostileCloneTest extends TestCase
{
    use RefreshDatabase;
    use RunsGit;

    public function test_a_commit_with_more_files_than_the_limit_is_refused_and_nothing_is_written_to_disk(): void
    {
        Storage::fake('local');
        config(['gitchronicle.git.max_files' => 20]);
        $origin = $this->origin(fn (string $path) => collect(range(1, 30))->each(fn (int $i) => File::put("{$path}/f{$i}.txt", "file {$i}")));
        $repository = Repository::factory()->create(['url' => $origin]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $repository->refresh();
        $this->assertSame('failed', $repository->status->value);
        $this->assertSame(StoredMessage::make('repositories.errors.too_many_files', ['max' => '20']), $repository->error);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("repositories/{$repository->id}"));
    }

    public function test_one_blob_referenced_many_times_counts_every_time_so_a_tiny_clone_cannot_fill_the_disk(): void
    {
        Storage::fake('local');
        config(['gitchronicle.git.max_bytes' => 100_000]);
        $origin = $this->origin(function (string $path): void {
            // The same 20 KB content in 10 files: the clone stores it once, the checkout would write it ten times.
            foreach (range(1, 10) as $i) {
                File::put("{$path}/copy{$i}.txt", str_repeat('A', 20_000));
            }
        });
        $repository = Repository::factory()->create(['url' => $origin]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $repository->refresh();
        $this->assertSame(StoredMessage::make('repositories.errors.too_large', ['max' => 0]), $repository->error);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("repositories/{$repository->id}"));
    }

    public function test_a_repository_that_fits_is_checked_out_normally(): void
    {
        Storage::fake('local');
        $origin = $this->origin(fn (string $path) => File::put("{$path}/a.txt", 'hello'));
        $repository = Repository::factory()->create(['url' => $origin]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        Storage::disk('local')->assertExists("repositories/{$repository->id}/a.txt");
    }

    public function test_a_full_storage_budget_refuses_a_new_clone(): void
    {
        Storage::fake('local');
        config(['gitchronicle.git.storage_budget_bytes' => 1024]);
        Storage::disk('local')->put('repositories/99/big.bin', str_repeat('x', 50_000));
        $origin = $this->origin(fn (string $path) => File::put("{$path}/a.txt", 'hello'));
        $repository = Repository::factory()->create(['url' => $origin]);

        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);

        $this->assertSame(StoredMessage::make('repositories.errors.storage_full'), $repository->refresh()->error);
    }

    public function test_a_first_run_that_fails_later_in_the_pipeline_does_not_keep_its_clone(): void
    {
        Storage::fake('local');
        $origin = $this->origin(fn (string $path) => File::put("{$path}/a.txt", 'hello'));
        $repository = Repository::factory()->create(['url' => $origin]);
        config(['services.analyzer.python' => '/nonexistent/python']);

        try {
            ProcessRepository::dispatch($repository, ProcessingTrigger::Manual);
        } catch (RepositoryProcessingException) {
            // The sync queue rethrows the failure of a step; the chain's catch has already run.
        }

        $this->assertSame('failed', $repository->refresh()->status->value);
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path("repositories/{$repository->id}"));
    }

    /**
     * A git repository in a temporary folder with the files $fill puts there.
     */
    private function origin(callable $fill): string
    {
        $path = sys_get_temp_dir().'/gc-origin-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($path);
        $fill($path);
        $this->commitEverything($path);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($path));

        return $path;
    }
}
