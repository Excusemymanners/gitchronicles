<?php

namespace Tests\Concerns;

use App\Enums\ProcessingTrigger;
use App\Jobs\ProcessRepository;
use App\Models\ProcessingRun;
use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The whole pipeline on the fixture project of the code analyzer (tests/Fixtures/analyzer-project),
 * with real git and no network: a local git repository on the fake "local" disk plays GitHub
 * ("origin"), the pipeline clones it through its folder path, and a test then commits changes to
 * the origin and processes again, as a push would. The queue is "sync": dispatch() runs every step.
 *
 * The modules of the fixture and who imports whom:
 * src/Http → src/Models → src/Support, src/Http → src/Support, frontend → frontend/components →
 * frontend/lib, frontend → frontend/lib, tools/cli → tools.
 */
trait ProcessesFixtureProject
{
    use RunsGit;

    /**
     * Create the origin with the fixture project in one commit, and a repository that points to it.
     */
    protected function repositoryWithFixtureOrigin(): Repository
    {
        File::copyDirectory(base_path('tests/Fixtures/analyzer-project'), $this->originPath());
        $this->commitEverything($this->originPath());

        return Repository::factory()->create(['url' => $this->originPath(), 'owner' => 'acme', 'name' => 'magazin']);
    }

    /**
     * The folder of the origin repository.
     */
    protected function originPath(): string
    {
        return Storage::disk('local')->path('origin');
    }

    /**
     * Change the origin and commit: write the given files (path => contents), delete the others.
     * Returns the sha of the new commit.
     *
     * @param  array<string, string>  $write
     * @param  list<string>  $delete
     */
    protected function pushToOrigin(array $write = [], array $delete = [], string $message = 'Schimbare'): string
    {
        foreach ($write as $path => $contents) {
            Storage::disk('local')->put("origin/{$path}", $contents);
        }

        foreach ($delete as $path) {
            Storage::disk('local')->delete("origin/{$path}");
        }

        $this->git($this->originPath(), ['add', '--all']);
        $this->git($this->originPath(), ['commit', '--quiet', '-m', $message]);

        return $this->git($this->originPath(), ['rev-parse', 'HEAD']);
    }

    /**
     * Run the pipeline for the repository and return the run it created.
     */
    protected function process(Repository $repository, bool $full = false): ProcessingRun
    {
        ProcessRepository::dispatch($repository, ProcessingTrigger::Manual, $full);

        return $repository->processingRuns()->latest('id')->firstOrFail();
    }
}
