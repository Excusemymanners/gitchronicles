<?php

namespace Tests\Feature\Documentation;

use App\Documentation\CloneFiles;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The files of a clone are read with CloneFiles: a README of hundreds of megabytes, a link out of the clone and a
 * folder that is a link are all things a stranger can put in a repository.
 */
class HostileCloneFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_huge_file_is_read_only_up_to_the_limit(): void
    {
        $repository = $this->clone(fn (string $path) => File::put("{$path}/README.md", str_repeat("Linie de text.\n", 300_000)));

        $content = (new CloneFiles($repository))->read('README.md');

        $this->assertNotNull($content);
        $this->assertLessThanOrEqual(CloneFiles::MAX_BYTES, strlen($content));
    }

    public function test_a_link_to_a_file_outside_the_clone_and_a_file_behind_a_linked_folder_are_not_read(): void
    {
        $outside = sys_get_temp_dir().'/gc-outside-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($outside);
        File::put("{$outside}/secret.txt", 'password');
        $repository = $this->clone(function (string $path) use ($outside): void {
            symlink("{$outside}/secret.txt", "{$path}/README.md");
            symlink($outside, "{$path}/docs");
        });
        $files = new CloneFiles($repository);

        $this->assertNull($files->read('README.md'));
        $this->assertNull($files->read('docs/secret.txt'));
        $this->assertFalse($files->exists('docs/secret.txt'));
        File::deleteDirectory($outside);
    }

    private function clone(callable $fill): Repository
    {
        Storage::fake('local');
        $repository = Repository::factory()->ready()->create();
        File::ensureDirectoryExists($repository->clonePath());
        $fill($repository->clonePath());

        return $repository;
    }
}
