<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Media\FactsCollector;
use App\Media\ScriptWriter;
use App\Media\ToolEnvironment;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts the presentation video and the slides of a repository. It is not a step of the processing
 * pipeline: FinishProcessing queues it after the repository is already "ready", on the separate "media"
 * queue, so a slow or failed render never delays or breaks the documentation.
 *
 * What it does:
 *
 * 1. nothing, when both the video and the slides were already made (or are being made) from the current
 *    commit — unless $force;
 * 2. marks both as pending in media_assets, for the current commit, so the page shows „În așteptare”;
 * 3. gathers the real facts (FactsCollector) and writes the script (ScriptWriter: Claude, or the template);
 * 4. queues the two render jobs, each with the data it needs. They are independent: if the video fails,
 *    the slides are still made.
 *
 * $renderNow runs the two render jobs right here instead of queueing them (`media:generate --now`).
 */
class GenerateMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The message for the page when the Node tools are missing.
     */
    public const NOT_INSTALLED_MESSAGE = 'Uneltele pentru video și slide-uri nu sunt instalate pe server (composer media:install).';

    /**
     * One attempt: the only slow part is the AI call, which already falls back to the template.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: one AI call (ClaudeCodeGenerator stops after 300).
     * With $renderNow the renders run inside this job too, so `media:generate --now` is not for a worker.
     */
    public int $timeout = 420;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * How long (in seconds) the uniqueness lock lasts if the job is never processed.
     */
    public int $uniqueFor = 1800;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Repository $repository,
        public bool $force = false,
        public bool $renderNow = false,
    ) {
        $this->onQueue(config('gitchronicle.media.queue'));
    }

    /**
     * The key of the uniqueness lock: one queued generation per repository.
     */
    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    /**
     * Execute the job.
     */
    public function handle(FactsCollector $collector, ScriptWriter $writer): void
    {
        $repository = $this->repository;
        $sha = $repository->last_sha;

        if (! $this->force && $this->isUpToDate($sha)) {
            return;
        }

        $assets = [$repository->mediaAsset(MediaKind::Video), $repository->mediaAsset(MediaKind::Slides)];

        foreach ($assets as $asset) {
            $asset->fill(['status' => MediaStatus::Pending, 'source_sha' => $sha, 'error' => null])->save();
        }

        if (! ToolEnvironment::isInstalled()) {
            foreach ($assets as $asset) {
                $asset->markFailed(self::NOT_INSTALLED_MESSAGE);
            }

            return;
        }

        $facts = $collector->collect($repository);
        $script = $writer->write($facts);

        $renders = [
            new RenderPresentationVideo($repository, $script->toArray(), $sha),
            new RenderPresentationSlides($repository, $facts->summary(), $script->scenes, $sha),
        ];

        foreach ($renders as $render) {
            $this->renderNow ? dispatch_sync($render) : dispatch($render);
        }
    }

    /**
     * Whatever went wrong before the render jobs were queued, the page must not stay on „În așteptare”.
     */
    public function failed(?Throwable $exception): void
    {
        $this->repository->mediaAssets()
            ->where('status', MediaStatus::Pending)
            ->get()
            ->each(fn (MediaAsset $asset) => $asset->markFailed(MediaAsset::GENERIC_ERROR));
    }

    /**
     * Whether the video and the slides both exist for this commit: ready, or still being made.
     * A failed one is made again.
     */
    private function isUpToDate(?string $sha): bool
    {
        $current = $this->repository->mediaAssets()
            ->where('source_sha', $sha)
            ->whereIn('status', [MediaStatus::Ready, MediaStatus::Pending, MediaStatus::Running])
            ->count();

        return $current === count(MediaKind::cases());
    }
}
