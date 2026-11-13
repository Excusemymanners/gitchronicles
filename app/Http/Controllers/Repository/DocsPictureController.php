<?php

namespace App\Http\Controllers\Repository;

use App\Documentation\DocumentVisuals;
use App\Documentation\ReadmeShowcase;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the pictures of the documentation pages from the app's own origin: the README's pictures copied at
 * the last processed commit (ReadmeShowcase) and the top of the capture of the repository's GitHub page. Public,
 * like the pages. The picture is chosen by an id looked up in the manifest ("readme-2", "github-page"), so a
 * request can never name a path; only the stored bytes of a checked picture are sent.
 *
 * Like the media files the answer carries an ETag and a Last-Modified (a repeat visit gets 304) and a cache
 * lifetime. The address of a picture ends in "?v=" with the commit, so a new commit is a new address. The
 * pictures come from a repository anyone can write: "nosniff" stops the browser guessing another type, and an
 * SVG opened on its own address is sandboxed (no scripts, nothing loaded), although it was already checked.
 */
class DocsPictureController extends Controller
{
    /**
     * The id of the opening picture (the GitHub page capture).
     */
    public const CAPTURE = 'github-page';

    /**
     * Send one picture of the repository's documentation.
     */
    public function __invoke(Request $request, Repository $repository, string $picture, ReadmeShowcase $showcase, DocumentVisuals $visuals): Response
    {
        if ($picture === self::CAPTURE) {
            $file = $visuals->captureFile($repository);
            $stored = $file === null ? null : ['path' => $file['path'], 'mime' => image_type_to_mime_type((int) @exif_imagetype($file['path']))];
        } else {
            $stored = $showcase->file($repository, $picture);
        }

        abort_if($stored === null || ! is_file($stored['path']), 404);

        $response = response()->file($stored['path'], [
            'Content-Type' => $stored['mime'],
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ])->setAutoEtag()->setAutoLastModified();
        $response->isNotModified($request);

        return $response;
    }
}
