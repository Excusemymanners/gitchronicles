<?php

namespace App\Http\Controllers\Repository;

use App\Enums\MediaKind;
use App\Enums\MediaLanguage;
use App\Enums\MediaStatus;
use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the files of the presentation video and slides. They live on the private "local" disk
 * (storage/app/private/media/{id}), so they are reachable only through these routes — public, like the
 * repositories themselves — and only for the fixed formats below: a request can never name a path.
 * The video's files exist once per language: "?lang=en" chooses one (MediaLanguage); without it, the
 * default language, else whichever video is ready (the links made before the languages existed).
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
    public function video(Request $request, Repository $repository): BinaryFileResponse
    {
        return $this->inline($request, $repository, MediaKind::Video, 'mp4', 'video/mp4');
    }

    /**
     * The poster: the frame shown before the video plays.
     */
    public function poster(Request $request, Repository $repository): BinaryFileResponse
    {
        return $this->inline($request, $repository, MediaKind::Video, 'poster', 'image/jpeg');
    }

    /**
     * The captions (WebVTT) of the <track> element.
     */
    public function captions(Request $request, Repository $repository): BinaryFileResponse
    {
        return $this->inline($request, $repository, MediaKind::Video, 'vtt', 'text/vtt; charset=UTF-8');
    }

    /**
     * Download the video (mp4) or the slides (pdf, pptx), named after the repository.
     */
    public function download(Request $request, Repository $repository, string $format): BinaryFileResponse
    {
        $download = self::DOWNLOADS[$format];
        $asset = $this->asset($request, $repository, $download['kind']);
        $language = $download['kind'] === MediaKind::Video && $asset !== null ? "{$asset->language->value}-" : '';

        return response()->download($this->path($asset, $format), "{$repository->owner}-{$repository->name}-{$language}{$download['suffix']}");
    }

    /**
     * A file shown inside the page. Its address changes at every generation ("?v="), so the browser may keep it.
     */
    private function inline(Request $request, Repository $repository, MediaKind $kind, string $format, string $contentType): BinaryFileResponse
    {
        return response()->file($this->path($this->asset($request, $repository, $kind), $format), [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * The row whose files are asked for: the slides, or the video of the language in "?lang=" — without
     * it, the video of the first configured language that is ready, else any ready video.
     */
    private function asset(Request $request, Repository $repository, MediaKind $kind): ?MediaAsset
    {
        $assets = $repository->mediaAssets()->where('kind', $kind)->get();

        if ($kind === MediaKind::Slides) {
            return $assets->first();
        }

        if ($request->has('lang')) {
            // An unknown language has no video (404), instead of silently getting another language's.
            $asked = MediaLanguage::tryFrom((string) $request->query('lang'));

            return $asked === null ? null : $assets->firstWhere('language', $asked);
        }

        $ready = $assets->where('status', MediaStatus::Ready);

        foreach (MediaLanguage::configured() as $language) {
            if ($ready->firstWhere('language', $language) !== null) {
                return $ready->firstWhere('language', $language);
            }
        }

        return $ready->first();
    }

    /**
     * The absolute path of a ready file, or a 404 when the asset is not ready or the file is missing.
     */
    private function path(?MediaAsset $asset, string $format): string
    {
        $file = $asset?->file($format);

        abort_if($file === null || ! Storage::disk('local')->exists($file), 404);

        return Storage::disk('local')->path($file);
    }
}
