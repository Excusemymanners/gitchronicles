<?php

namespace Tests\Feature\Git;

use App\Git\ChangeSet;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RunsGit;
use Tests\TestCase;

/**
 * ChangeSet with real git on a small local repository.
 */
class ChangeSetTest extends TestCase
{
    use RunsGit;

    public function test_lists_the_files_added_modified_and_deleted_between_two_commits(): void
    {
        $path = $this->repositoryWith(['app.php' => "<?php\n", 'old.php' => "<?php\n", 'README.md' => "# Proiect\n", 'docs/ghid nou.md' => "Ghid\n"]);
        $from = $this->git($path, ['rev-parse', 'HEAD']);
        Storage::disk('local')->put('repo/app.php', "<?php\n\necho 'Salut';\n");
        Storage::disk('local')->put('repo/src/Nou.php', "<?php\n");
        Storage::disk('local')->delete('repo/old.php');
        $this->git($path, ['add', '--all']);
        $this->git($path, ['commit', '--quiet', '-m', 'Schimbări']);
        $to = $this->git($path, ['rev-parse', 'HEAD']);

        $changes = ChangeSet::between($path, $from, $to);

        $this->assertSame(['src/Nou.php'], $changes->added);
        $this->assertSame(['app.php'], $changes->modified);
        $this->assertSame(['old.php'], $changes->deleted);
        $this->assertSame(3, $changes->count());
        $this->assertSame(['src/Nou.php', 'app.php', 'old.php'], $changes->paths());
        $this->assertFalse($changes->isEmpty());
    }

    public function test_a_renamed_file_is_one_deleted_and_one_added_file(): void
    {
        $path = $this->repositoryWith(['docs/ghid nou.md' => "Ghid cu spații în nume\n"]);
        $from = $this->git($path, ['rev-parse', 'HEAD']);
        $this->git($path, ['mv', 'docs/ghid nou.md', 'docs/ghid.md']);
        $this->git($path, ['commit', '--quiet', '-m', 'Redenumire']);

        $changes = ChangeSet::between($path, $from, 'HEAD');

        $this->assertSame(['docs/ghid.md'], $changes->added);
        $this->assertSame(['docs/ghid nou.md'], $changes->deleted);
        $this->assertSame([], $changes->modified);
    }

    public function test_the_same_commit_has_no_changes(): void
    {
        $path = $this->repositoryWith(['app.php' => "<?php\n"]);

        $changes = ChangeSet::between($path, 'HEAD', 'HEAD');

        $this->assertTrue($changes->isEmpty());
    }

    public function test_a_commit_the_clone_does_not_have_cannot_be_compared(): void
    {
        $path = $this->repositoryWith(['app.php' => "<?php\n"]);

        $this->assertNull(ChangeSet::between($path, str_repeat('a', 40), 'HEAD'));
    }

    /**
     * A git repository on the fake disk with these files in one commit; returns its path.
     *
     * @param  array<string, string>  $files
     */
    private function repositoryWith(array $files): string
    {
        foreach ($files as $file => $contents) {
            Storage::disk('local')->put("repo/{$file}", $contents);
        }

        $path = Storage::disk('local')->path('repo');
        $this->commitEverything($path);

        return $path;
    }
}
