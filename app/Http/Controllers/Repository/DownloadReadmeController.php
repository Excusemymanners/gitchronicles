<?php

namespace App\Http\Controllers\Repository;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Http\Response;

/**
 * „Descarcă README.md”: the generated README of a repository as a Markdown file. Public, like the docs;
 * 404 until the README is written.
 */
class DownloadReadmeController extends Controller
{
    /**
     * Send the README as an attachment named README.md.
     */
    public function __invoke(Repository $repository): Response
    {
        $readme = Document::whereBelongsTo($repository)->where('slug', Document::README)->firstOrFail();

        return response($readme->markdown, 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="README.md"',
        ]);
    }
}
