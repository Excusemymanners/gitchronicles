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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the files of the presentation video and slides. They live on the private "local" disk
 * (storage/app/private/media/{id}), so they are reachable only through these routes — public, like the
 * repositories themselves — and only for the fixed formats below: a request can never name a path.
 * The video's files exist once per language: "?lang=en" chooses one (MediaLanguage); without it, the
 * default language, else whichever video is ready (the links made before the languages existed).
 *
 * The <video> element asks for pieces of the file with "Range" requests, to seek without downloading everything.
 * A request like "bytes=0-" would be answered with the rest of the file, and the PHP development server (one
 * worker) would be busy with it while the browser buffers, blocking every other page. So a range answer is
 * BOUNDED (config gitchronicle.media.max_range_bytes, 1 MB): a 206 with the first bytes of what was asked, and
 * the browser asks for the next piece by itself. The files shown in the page also carry an ETag and a
 * Last-Modified, so a repeat visit is answered with 304 and no file is sent.
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
    public function video(Request $request, Repository $repository): Response
    {
        return $this->inline($request, $repository, MediaKind::Video, 'mp4', 'video/mp4');
    }

    /**
     * The poster: the frame shown before the video plays.
     */
    public function poster(Request $request, Repository $repository): Response
    {
        return $this->inline($request, $repository, MediaKind::Video, 'poster', 'image/jpeg');
    }

    /**
     * The captions (WebVTT) of the <track> element.
     */
    public function captions(Request $request, Repository $repository): Response
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
     * A "Range" request gets a bounded 206 (see the class comment); any other request gets the whole file, or a
     * 304 when the browser already has it.
     */
    private function inline(Request $request, Repository $repository, MediaKind $kind, string $format, string $contentType): Response
    {
        $path = $this->path($this->asset($request, $repository, $kind), $format);
        $headers = ['Content-Type' => $contentType, 'Cache-Control' => 'public, max-age=3600', 'Accept-Ranges' => 'bytes'];

        if ($request->headers->has('Range')) {
            return $this->partial($request, $path, $headers);
        }

        $response = response()->file($path, $headers)->setAutoEtag()->setAutoLastModified();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * The answer to a "Range: bytes=a-b" request (one range; "bytes=a-" and "bytes=-n" too): 206 with at most
     * max_range_bytes starting at the first byte asked for, or 416 when that byte is beyond the end of the file.
     * A header that is not a single byte range is ignored, as the HTTP rules allow, and the whole file is sent.
     *
     * @param  array<string, string>  $headers
     */
    private function partial(Request $request, string $path, array $headers): Response
    {
        $size = (int) filesize($path);

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) $request->header('Range')), $match) !== 1 || ($match[1] === '' && $match[2] === '')) {
            return response()->file($path, $headers);
        }

        if ($match[1] === '') {
            // "bytes=-500": the last 500 bytes.
            $start = max(0, $size - (int) $match[2]);
            $end = $size - 1;
        } else {
            $start = (int) $match[1];
            $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
        }

        if ($start >= $size || $start > $end) {
            return response('', 416, ['Content-Range' => "bytes */{$size}"] + $headers);
        }

        $end = min($end, $start + max(1, (int) config('gitchronicle.media.max_range_bytes')) - 1);
        $length = $end - $start + 1;

        return new StreamedResponse(function () use ($path, $start, $length): void {
            $file = fopen($path, 'rb');
            fseek($file, $start);
            echo fread($file, $length);
            fclose($file);
        }, 206, $headers + ['Content-Range' => "bytes {$start}-{$end}/{$size}", 'Content-Length' => (string) $length]);
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
