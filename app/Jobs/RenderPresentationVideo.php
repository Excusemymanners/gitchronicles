<?php

namespace App\Jobs;

use App\Enums\MediaKind;
use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use App\Media\GitHubCapture;
use App\Media\PageCapture;
use App\Media\PresentationScript;
use App\Media\VideoRenderer;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Renders the presentation video of a repository in ONE language from its script (VideoRenderer: the
 * camera on the photographed GitHub page, the Piper voice, HyperFrames, the music and the mix) and
 * records the result in that language's row of media_assets: the files, the length, the scenes with
 * their times and sources, who wrote the texts and with which voice, the input hash and the script
 * (so an unchanged repository is not rendered or sent to the AI again), and whether the GitHub page
 * could be photographed (when not, the reason — the video then has typographic scenes).
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
    public int $timeout = 1200;

    /**
     * If the repository was deleted while the job waited in the queue, drop the job silently.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     *
     * @param  array{scenes: list<array<string, mixed>>, writer: string, model: string|null, input_tokens: int, output_tokens: int, rejected: list<array{scene: string, reason: string}>, note: string|null, answer: array<string, mixed>|null, context_hash: string|null}  $script  PresentationScript::toArray()
     * @param  string  $language  the code of the language to render ("en", "ro")
     * @param  string|null  $sourceSha  the commit the script was written from
     * @param  string|null  $inputHash  the hash of what the video is built from (GenerateMedia::inputHash())
     * @param  string|null  $captureReason  why the GitHub page was not photographed (null = it was)
     */
    public function __construct(
        public Repository $repository,
        public array $script,
        public string $language,
        public ?string $sourceSha,
        public ?string $inputHash = null,
        public ?string $captureReason = null,
    ) {
        $this->onQueue(config('gitchronicle.media.queue'));
    }

    /**
     * Execute the job.
     */
    public function handle(VideoRenderer $renderer, GitHubCapture $captures): void
    {
        $language = MediaLanguage::from($this->language);
        $asset = $this->repository->videoAsset($language);
        $asset->fill(['status' => MediaStatus::Running, 'source_sha' => $this->sourceSha, 'error' => null])->save();

        $capture = $this->captureReason === null ? $captures->stored($this->repository) : PageCapture::missing($this->captureReason);

        try {
            $result = $renderer->render($this->repository, PresentationScript::flatten($this->script['scenes'], $language), $language, $capture);
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
                'input_hash' => $this->inputHash,
                'script' => [
                    'writer' => $this->script['writer'],
                    'model' => $this->script['model'],
                    'context_hash' => $this->script['context_hash'] ?? null,
                    'answer' => $this->script['answer'] ?? null,
                ],
                'capture' => [
                    'captured_at' => $capture->capturedAt(),
                    'reason' => $capture->isAvailable() ? null : $capture->reason,
                ],
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
            ->where('language', $this->language)
            ->get()
            ->each(fn (MediaAsset $asset) => $asset->markFailed(self::FAILED_MESSAGE));
    }
}
