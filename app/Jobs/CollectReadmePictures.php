<?php

namespace App\Jobs;

use App\Documentation\ReadmeShowcase;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies the README's own pictures and descriptive sections of the repository's current commit
 * (ReadmeShowcase). Like GenerateMedia it is not a step of the pipeline: FinishProcessing queues it after the
 * repository is "ready", on the "media" queue, because downloading pictures is slow network work and a README
 * without pictures (or a picture host that does not answer) must never delay or break the documentation.
 */
class CollectReadmePictures implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One attempt: the pages simply show no pictures until the next commit is processed.
     */
    public int $tries = 1;

    /**
     * The downloads have their own time limit each; this bounds all of them.
     */
    public int $timeout = 240;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(public Repository $repository)
    {
        $this->onQueue((string) config('gitchronicle.media.queue'));
    }

    /**
     * One collection per repository at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    /**
     * Execute the job.
     */
    public function handle(ReadmeShowcase $showcase): void
    {
        $showcase->collect($this->repository);
    }
}
