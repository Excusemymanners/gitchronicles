<?php

namespace App\Jobs;

use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use App\Media\FactsCollector;
use App\Media\GitHubCapture;
use App\Media\PageCapture;
use App\Media\PresentationFacts;
use App\Media\ScriptWriter;
use App\Media\ToolEnvironment;
use App\Models\MediaAsset;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Starts the presentation video (one per language) and the slides of a repository. It is not a step of
 * the processing pipeline: FinishProcessing queues it after the repository is already "ready", on the
 * separate "media" queue, so a slow or failed render never delays or breaks the documentation.
 *
 * What it does:
 *
 * 1. nothing, when the videos and the slides were already made (or are being made) from the current
 *    commit — unless $force;
 * 2. gathers the real facts (FactsCollector) and photographs the repository's GitHub page (GitHubCapture);
 * 3. compares the hash of everything the video is built from (the texts of the sources, the facts in the
 *    form the video shows them, what the photo of the page says, the languages) with the hash kept with
 *    the existing videos: after an update that changed none of it, the videos are only marked as made
 *    for the new commit — no AI call, no render;
 * 4. marks what will be made as pending in media_assets, so the page shows „În așteptare”;
 * 5. writes the script once for all languages (ScriptWriter: Claude, or the template). While the sources
 *    did not change, the texts Claude already wrote are used again instead of a new call — unless $rewrite;
 * 6. queues one video render per language and the slides render, each with the data it needs. They are
 *    independent: if one fails, the others are still made.
 *
 * $renderNow runs the render jobs right here instead of queueing them (`repositories:media --now`).
 */
class GenerateMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The message for the page when the Node tools are missing.
     */
    public const NOT_INSTALLED_KEY = 'media.errors.not_installed';

    /**
     * Changing how the video looks or is told (the composition, the scenes) makes the old videos
     * outdated even when the repository did not change: this number is part of the input hash.
     */
    public const VIDEO_VERSION = 4;

    /**
     * One attempt: the only slow part is the AI call, which already falls back to the template.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the photo of the GitHub page (config
     * gitchronicle.media.capture.timeout) and one AI call (ClaudeCodeGenerator stops after 300).
     * With $renderNow the renders run inside this job too, so `repositories:media --now` is not for a worker.
     */
    public int $timeout = 540;

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
     *
     * @param  bool  $force  make everything again, even when it exists for the current commit (with a new photo of the page)
     * @param  bool  $renderNow  render here instead of queueing the render jobs
     * @param  bool  $rewrite  ask the AI for new texts even when the sources did not change
     */
    public function __construct(
        public Repository $repository,
        public bool $force = false,
        public bool $renderNow = false,
        public bool $rewrite = false,
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
    public function handle(FactsCollector $collector, ScriptWriter $writer, GitHubCapture $capturer): void
    {
        $repository = $this->repository;
        $sha = $repository->last_sha;
        $languages = MediaLanguage::configured();

        $videos = array_map(fn (MediaLanguage $language): MediaAsset => $repository->videoAsset($language), $languages);
        $slides = $repository->slidesAsset();

        $needsVideos = $this->force || ! $this->areCurrent($videos, $sha);
        $needsSlides = $this->force || ! $this->areCurrent([$slides], $sha);

        if (! $needsVideos && ! $needsSlides) {
            return;
        }

        if (! ToolEnvironment::isInstalled()) {
            foreach ([...($needsVideos ? $videos : []), ...($needsSlides ? [$slides] : [])] as $asset) {
                $asset->fill(['status' => MediaStatus::Failed, 'source_sha' => $sha, 'error' => StoredMessage::make(self::NOT_INSTALLED_KEY)])->save();
            }

            return;
        }

        $facts = $collector->collect($repository);
        $capture = $needsVideos ? $capturer->capture($repository, fresh: $this->force) : PageCapture::missing(StoredMessage::make(GitHubCapture::FAILED_KEY));
        $inputHash = self::inputHash($facts, $capture, $languages);

        if ($needsVideos && ! $this->force && $this->wereMadeFrom($videos, $inputHash)) {
            // Nothing the video shows has changed: the same files now stand for the new commit.
            foreach ($videos as $video) {
                $video->update(['source_sha' => $sha]);
            }

            $needsVideos = false;
        }

        if (! $needsVideos && ! $needsSlides) {
            return;
        }

        foreach ([...($needsVideos ? $videos : []), ...($needsSlides ? [$slides] : [])] as $asset) {
            $asset->fill(['status' => MediaStatus::Pending, 'source_sha' => $sha, 'error' => null])->save();
        }

        $script = $writer->write($facts, $this->rewrite ? null : $this->previousScript($videos));
        $renders = [];

        if ($needsVideos) {
            foreach ($languages as $language) {
                $renders[] = new RenderPresentationVideo($repository, $script->toArray(), $language->value, $sha, $inputHash, $capture->isAvailable() ? null : $capture->reason);
            }
        }

        if ($needsSlides) {
            // The slides are generated content: they are written in English, like the documentation.
            $renders[] = new RenderPresentationSlides($repository, $facts->summary(), $script->scenesFor(MediaLanguage::En), $sha);
        }

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
            ->each(fn (MediaAsset $asset) => $asset->markFailed(StoredMessage::make(MediaAsset::GENERIC_ERROR_KEY)));
    }

    /**
     * The hash of everything the videos are built from: what the facts show (PresentationFacts::videoFingerprint()),
     * what the photo of the GitHub page says (PageCapture::fingerprint()), the languages and the version of
     * the video itself.
     *
     * @param  list<MediaLanguage>  $languages
     */
    public static function inputHash(PresentationFacts $facts, PageCapture $capture, array $languages): string
    {
        return hash('sha256', implode('|', [
            self::VIDEO_VERSION,
            $facts->videoFingerprint(),
            $capture->fingerprint(),
            implode(',', array_column($languages, 'value')),
        ]));
    }

    /**
     * Whether every asset exists for this commit: ready, or still being made. A failed one is made again.
     *
     * @param  list<MediaAsset>  $assets
     */
    private function areCurrent(array $assets, ?string $sha): bool
    {
        foreach ($assets as $asset) {
            if (! $asset->exists || $asset->source_sha !== $sha || $asset->status === MediaStatus::Failed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every video is ready and was built from exactly this input.
     *
     * @param  list<MediaAsset>  $videos
     */
    private function wereMadeFrom(array $videos, string $inputHash): bool
    {
        foreach ($videos as $video) {
            if (! $video->exists || $video->status !== MediaStatus::Ready || ($video->meta['input_hash'] ?? null) !== $inputHash) {
                return false;
            }
        }

        return true;
    }

    /**
     * The script kept with an existing video (the AI's answer and the hash of what it was given), so the
     * writer can use the same texts again.
     *
     * @param  list<MediaAsset>  $videos
     * @return array<string, mixed>|null
     */
    private function previousScript(array $videos): ?array
    {
        foreach ($videos as $video) {
            if (is_array($video->meta['script'] ?? null)) {
                return $video->meta['script'];
            }
        }

        return null;
    }
}
