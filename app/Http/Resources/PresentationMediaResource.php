<?php

namespace App\Http\Resources;

use App\Enums\MediaKind;
use App\Enums\MediaLanguage;
use App\Models\MediaAsset;
use App\Models\Repository;
use App\Support\StoredMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the „Video” page shows (the PresentationMedia type in TypeScript): the video of every language with
 * its files, its scenes and where every sentence comes from, the slides with their downloads, and whether
 * the visitor may start a generation. A video or the slides are missing until their row exists in
 * media_assets (nothing was generated or queued yet).
 *
 * The addresses are relative to the site and carry "?v=" with the time of the generation, so the browser
 * never shows an old video after a new one was made.
 *
 * @mixin Repository
 */
class PresentationMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{videos: list<array<string, mixed>>, default_language: string, slides: array<string, mixed>|null, is_working: bool, can_generate: bool}
     */
    public function toArray(Request $request): array
    {
        $assets = $this->mediaAssets()->get();
        $slides = $assets->firstWhere('kind', MediaKind::Slides);
        $videos = $assets->where('kind', MediaKind::Video);

        // The configured languages first, in their order; then a video left from a language no longer configured.
        $configured = array_column(MediaLanguage::configured(), 'value');
        $videos = $videos->sortBy(fn (MediaAsset $video): int => ($position = array_search($video->language->value, $configured, true)) === false ? 99 : $position)->values();

        return [
            'videos' => $videos->map(fn (MediaAsset $video): array => [
                ...$this->state($video),
                'language' => $video->language->value,
                'language_label' => $video->language->label(),
                'duration_ms' => $video->duration_ms,
                'src' => $this->fileUrl($video, 'mp4', 'repositories.media.video'),
                'poster' => $this->fileUrl($video, 'poster', 'repositories.media.poster'),
                'captions' => $this->fileUrl($video, 'vtt', 'repositories.media.captions'),
                'download' => $this->downloadUrl($video, 'mp4'),
                'writer' => $video->meta['writer'] ?? null,
                'note' => StoredMessage::render($video->meta['note'] ?? null),
                'voice' => $video->meta['voice'] ?? null,
                'capture_note' => StoredMessage::render($video->meta['capture']['reason'] ?? null),
                'scenes' => array_map($this->scene(...), $video->meta['scenes'] ?? []),
            ])->all(),
            'default_language' => $videos->first()?->language->value ?? MediaLanguage::default()->value,
            'slides' => $slides === null ? null : [
                ...$this->state($slides),
                'downloads' => [
                    'pdf' => $this->downloadUrl($slides, 'pdf'),
                    'pptx' => $this->downloadUrl($slides, 'pptx'),
                ],
                'titles' => $slides->meta['slides'] ?? [],
                'pptx_size_bytes' => $slides->meta['pptx_size_bytes'] ?? null,
            ],
            'is_working' => $assets->contains(fn (MediaAsset $asset): bool => $asset->status->isWorking()),
            'can_generate' => $request->user()?->can('generateMedia', $this->resource) ?? false,
        ];
    }

    /**
     * What the video and the slides have in common: the state, the error, the date and whether the
     * repository moved to a newer commit since they were made.
     *
     * @return array{status: string, status_label: string, error: string|null, generated_at: string|null, size_bytes: int|null, is_stale: bool}
     */
    private function state(MediaAsset $asset): array
    {
        return [
            'status' => $asset->status->value,
            'status_label' => $asset->status->label(),
            'error' => StoredMessage::render($asset->error),
            'generated_at' => $asset->generated_at?->toIso8601String(),
            'size_bytes' => $asset->size_bytes,
            'is_stale' => $asset->source_sha !== $this->last_sha,
        ];
    }

    /**
     * One scene for the list under the video: when it plays, what the screen shows, what the voice says
     * and the quoted source.
     *
     * @param  array<string, mixed>  $scene
     * @return array{key: string, label: string, start_ms: int, end_ms: int, title: string|null, text: string|null, command: string|null, narration: string|null, source: array{label: string, quote: string, url: string|null}|null}
     */
    private function scene(array $scene): array
    {
        $start = (float) ($scene['start'] ?? 0);

        return [
            'key' => (string) $scene['key'],
            'label' => (string) ($scene['label'] ?? $scene['key']),
            'start_ms' => (int) round($start * 1000),
            'end_ms' => (int) round(($start + (float) ($scene['duration'] ?? 0)) * 1000),
            'title' => $scene['title'] ?? null,
            'text' => $scene['text'] ?? $scene['note'] ?? null,
            'command' => $scene['command'] ?? null,
            'narration' => $scene['narration'] ?? null,
            'source' => $scene['source'] ?? null,
        ];
    }

    /**
     * The address that shows a file in the page (the video, its poster, its captions), or null while it does not exist.
     */
    private function fileUrl(MediaAsset $asset, string $format, string $routeName): ?string
    {
        if ($asset->file($format) === null) {
            return null;
        }

        return route($routeName, ['repository' => $this->id, 'lang' => $asset->language->value, 'v' => $asset->generated_at?->timestamp], absolute: false);
    }

    /**
     * The address that downloads a file (mp4, pdf, pptx), or null while it does not exist.
     */
    private function downloadUrl(MediaAsset $asset, string $format): ?string
    {
        if ($asset->file($format) === null) {
            return null;
        }

        return route('repositories.media.download', [
            'repository' => $this->id,
            'format' => $format,
            ...($asset->kind === MediaKind::Video ? ['lang' => $asset->language->value] : []),
        ], absolute: false);
    }
}
