<?php

namespace App\Http\Controllers\Repository;

use App\Enums\MediaKind;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the files of the presentation video and slides. They live on the private "local" disk
 * (storage/app/private/media/{id}), so they are reachable only through these routes — public, like the
 * repositories themselves — and only for the fixed formats below: a request can never name a path.
 *
 * response()->file() and response()->download() return a BinaryFileResponse, which answers "Range"
 * requests (206 Partial Content): the <video> element needs them to seek without downloading everything.
 */
class MediaFileController extends Controller
{
    /**
     * Which asset holds each downloadable format, and the name of the downloaded file.
     *
     * @var array<string, array{kind: MediaKind, suffix: string}>
     */
    private const DOWNLOADS = [
        'mp4' => ['kind' => MediaKind::Video, 'suffix' => 'video.mp4'],
        'pdf' => ['kind' => MediaKind::Slides, 'suffix' => 'prezentare.pdf'],
        'pptx' => ['kind' => MediaKind::Slides, 'suffix' => 'prezentare.pptx'],
    ];

    /**
     * The video, played in the page.
     */
    public function video(Repository $repository): BinaryFileResponse
    {
        return $this->inline($repository, MediaKind::Video, 'mp4', 'video/mp4');
    }

    /**
     * The poster: the frame shown before the video plays.
     */
    public function poster(Repository $repository): BinaryFileResponse
    {
        return $this->inline($repository, MediaKind::Video, 'poster', 'image/jpeg');
    }

    /**
     * The captions (WebVTT) of the <track> element.
     */
    public function captions(Repository $repository): BinaryFileResponse
    {
        return $this->inline($repository, MediaKind::Video, 'vtt', 'text/vtt; charset=UTF-8');
    }

    /**
     * Download the video (mp4) or the slides (pdf, pptx), named after the repository.
     */
    public function download(Repository $repository, string $format): BinaryFileResponse
    {
        $download = self::DOWNLOADS[$format];
        $path = $this->path($repository, $download['kind'], $format);

        return response()->download($path, "{$repository->owner}-{$repository->name}-{$download['suffix']}");
    }

    /**
     * A file shown inside the page. Its address changes at every generation ("?v="), so the browser may keep it.
     */
    private function inline(Repository $repository, MediaKind $kind, string $format, string $contentType): BinaryFileResponse
    {
        return response()->file($this->path($repository, $kind, $format), [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * The absolute path of a ready file, or a 404 when the asset is not ready or the file is missing.
     */
    private function path(Repository $repository, MediaKind $kind, string $format): string
    {
        $file = $repository->mediaAssets()->where('kind', $kind)->first()?->file($format);

        abort_if($file === null || ! Storage::disk('local')->exists($file), 404);

        return Storage::disk('local')->path($file);
    }
}
