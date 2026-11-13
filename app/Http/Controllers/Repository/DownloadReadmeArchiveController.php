<?php

namespace App\Http\Controllers\Repository;

use App\Documentation\ReadmeWithPictures;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Http\Response;
use RuntimeException;
use ZipArchive;

/**
 * „README with pictures (.zip)”: README.md with relative links plus an images/ folder (the README's own pictures,
 * the capture of the GitHub page, the facts strip, the architecture diagram), ready to drop into a repository.
 * Public like the docs; 404 until the README is written. The plain README.md download stays as it was.
 */
class DownloadReadmeArchiveController extends Controller
{
    /**
     * Send the archive, named after the repository.
     */
    public function __invoke(Repository $repository, ReadmeWithPictures $builder): Response
    {
        $readme = Document::whereBelongsTo($repository)->where('slug', Document::README)->firstOrFail();
        $built = $builder->build($repository, $readme);

        $path = tempnam(sys_get_temp_dir(), 'readme-zip');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The archive could not be created.');
        }

        $zip->addFromString('README.md', $built['markdown']);

        foreach ($built['files'] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();
        $content = (string) file_get_contents($path);
        unlink($path);

        return response($content, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$repository->owner.'-'.$repository->name.'-README.zip"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
