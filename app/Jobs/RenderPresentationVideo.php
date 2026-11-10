<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Media\VideoRenderer;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders the presentation video of a repository from its script (VideoRenderer: the Piper voice,
 * HyperFrames, the music and the mix) and records the result in media_assets: the files, the length,
 * the scenes with their times and sources, who wrote the texts and with which voice.
 *
 * A failure of a tool is not thrown to the queue: trying again would fail the same way. The row
 * becomes "failed" with a message for the page and the details go to the log.
 */
class RenderPresentationVideo implements ShouldQueue
{
    use Queueable;

    /**
     * The message for the page when the render fails.
     */
    public const FAILED_MESSAGE = 'Randarea video-ului a eșuat. Detaliile sunt în jurnalul aplicației.';

    /**
     * One attempt: the render does not use the network, so an error would happen again.
     */
    public int $tries = 1;

    /**
     * The maximum number of seconds the job may run: the render (VideoRenderer::RENDER_TIMEOUT_SECONDS)
     * plus the voice, the music and the mix.
     */
    public int $timeout = 900;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param  array{scenes: list<array<string, mixed>>, writer: string, model: string|null, input_tokens: int, output_tokens: int, rejected: list<array{scene: string, reason: string}>, note: string|null}  $script  PresentationScript::toArray()
     * @param  string|null  $sourceSha  the commit the script was written from
     */
    public function __construct(
        public Repository $repository,
        public array $script,
        public ?string $sourceSha,
    ) {
        $this->onQueue(config('gitchronicle.media.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(VideoRenderer $renderer): void
    {
        $asset = $this->repository->mediaAsset(MediaKind::Video);
        $asset->fill(['status' => MediaStatus::Running, 'source_sha' => $this->sourceSha, 'error' => null])->save();

        try {
            $result = $renderer->render($this->repository, $this->script['scenes']);
        } catch (Throwable $exception) {
            report($exception);
            $asset->markFailed(self::FAILED_MESSAGE);

            return;
        }

        $asset->update([
            'status' => MediaStatus::Ready,
            'path' => $result['files']['mp4'],
            'duration_ms' => $result['duration_ms'],
            'size_bytes' => $result['size_bytes'],
            'generated_at' => now(),
            'meta' => [
                'files' => $result['files'],
                'scenes' => $result['scenes'],
                'voice' => $result['voice'],
                'render_ms' => $result['render_ms'],
                'writer' => $this->script['writer'],
                'model' => $this->script['model'],
                'note' => $this->script['note'],
                'rejected' => $this->script['rejected'],
                'input_tokens' => $this->script['input_tokens'],
                'output_tokens' => $this->script['output_tokens'],
            ],
        ]);
    }

    /**
     * The worker stopped the job (a timeout, a crash): the page must not stay on „Se generează”.
     */
    public function failed(?Throwable $exception): void
    {
        $this->repository->mediaAssets()
            ->where('kind', MediaKind::Video)
            ->get()
            ->each(fn (MediaAsset $asset) => $asset->markFailed(self::FAILED_MESSAGE));
    }
}
