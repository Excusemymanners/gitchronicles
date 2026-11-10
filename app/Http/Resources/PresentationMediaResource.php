<?php

namespace App\Http\Resources;

use App\Enums\MediaKind;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the „Video” page shows (the PresentationMedia type in TypeScript): the video with its files,
 * its scenes and where every sentence comes from, and the slides with their downloads. Each one is null
 * until its row exists in media_assets (nothing was generated or queued yet).
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
     * @return array{video: array<string, mixed>|null, slides: array<string, mixed>|null, is_working: bool}
     */
    public function toArray(Request $request): array
    {
        $assets = $this->mediaAssets()->get()->keyBy(fn (MediaAsset $asset): string => $asset->kind->value);
        $video = $assets->get(MediaKind::Video->value);
        $slides = $assets->get(MediaKind::Slides->value);

        return [
            'video' => $video === null ? null : [
                ...$this->state($video),
                'duration_ms' => $video->duration_ms,
                'src' => $this->fileUrl($video, 'mp4', 'repositories.media.video'),
                'poster' => $this->fileUrl($video, 'poster', 'repositories.media.poster'),
                'captions' => $this->fileUrl($video, 'vtt', 'repositories.media.captions'),
                'download' => $this->downloadUrl($video, 'mp4'),
                'writer' => $video->meta['writer'] ?? null,
                'note' => $video->meta['note'] ?? null,
                'voice' => $video->meta['voice'] ?? null,
                'scenes' => array_map($this->scene(...), $video->meta['scenes'] ?? []),
            ],
            'slides' => $slides === null ? null : [
                ...$this->state($slides),
                'downloads' => [
                    'pdf' => $this->downloadUrl($slides, 'pdf'),
                    'pptx' => $this->downloadUrl($slides, 'pptx'),
                ],
                'titles' => $slides->meta['slides'] ?? [],
            ],
            'is_working' => $assets->contains(fn (MediaAsset $asset): bool => $asset->status->isWorking()),
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
            'error' => $asset->error,
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

        return route($routeName, ['repository' => $this->id, 'v' => $asset->generated_at?->timestamp], absolute: false);
    }

    /**
     * The address that downloads a file (mp4, pdf, pptx), or null while it does not exist.
     */
    private function downloadUrl(MediaAsset $asset, string $format): ?string
    {
        if ($asset->file($format) === null) {
            return null;
        }

        return route('repositories.media.download', ['repository' => $this->id, 'format' => $format], absolute: false);
    }
}
