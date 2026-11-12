<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use App\Media\SlideRenderer;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders the slides of a repository (SlideRenderer: Marp, PDF and PPTX) from the same facts and
 * script as the video, in English (generated content is written in English), and records the result in
 * media_assets: the files and the title of every slide.
 *
 * Like the video job, a failure of a tool marks the row "failed" instead of being thrown to the queue.
 */
class RenderPresentationSlides implements ShouldQueue
{
    use Queueable;

    /**
     * The message for the page when the render fails.
     */
    public const FAILED_MESSAGE = 'Generarea slide-urilor a eșuat. Detaliile sunt în jurnalul aplicației.';

    /**
     * One attempt: the render does not use the network, so an error would happen again.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the diagram and two Marp conversions.
     */
    public int $timeout = 420;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @param  list<array<string, mixed>>  $scenes  the English scenes of the script (PresentationScript::scenesFor())
     * @param  string|null  $sourceSha  the commit the facts were gathered from
     */
    public function __construct(
        public Repository $repository,
        public array $facts,
        public array $scenes,
        public ?string $sourceSha,
    ) {
        $this->onQueue(config('gitchronicle.media.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(SlideRenderer $renderer): void
    {
        $asset = $this->repository->slidesAsset();
        $asset->fill(['status' => MediaStatus::Running, 'language' => MediaLanguage::En, 'source_sha' => $this->sourceSha, 'error' => null])->save();

        try {
            $result = $renderer->render($this->repository, $this->facts, $this->scenes);
        } catch (Throwable $exception) {
            report($exception);
            $asset->markFailed(self::FAILED_MESSAGE);

            return;
        }

        $asset->update([
            'status' => MediaStatus::Ready,
            'path' => $result['files']['pdf'],
            'size_bytes' => $result['size_bytes'],
            'generated_at' => now(),
            'meta' => [
                'files' => $result['files'],
                'slides' => $result['slides'],
                'pptx_size_bytes' => $result['pptx_size_bytes'],
                'accent' => $result['accent'],
                'render_ms' => $result['render_ms'],
            ],
        ]);
    }

    /**
     * The worker stopped the job (a timeout, a crash): the page must not stay on „Se generează”.
     */
    public function failed(?Throwable $exception): void
    {
        $this->repository->mediaAssets()
            ->where('kind', MediaKind::Slides)
            ->get()
            ->each(fn (MediaAsset $asset) => $asset->markFailed(self::FAILED_MESSAGE));
    }
}
