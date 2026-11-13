<?php

namespace App\Documentation;

use App\Media\Slides\SlideIdentity;
use App\Models\Repository;
use App\Support\SafeDownload;
use Illuminate\Support\Facades\Storage;

/**
 * The README's own pictures and descriptive sections, kept once per processed commit and served by the app.
 *
 * collect() reads the README of the clone, finds its pictures (ReadmeImages) and copies each one: a file of
 * the clone (a normal file inside it, never a link) or a download through SafeDownload (https, the host
 * allow-list, a size cap). Every picture must pass SlideIdentity::isSafeImage (a known type, a sane size, an SVG
 * without scripts or links), small ones are left out, and the same picture twice is kept once. The result is
 * a manifest.json and the picture files in media/{id}/readme/{sha}/ on the private disk; the folders of older
 * commits are deleted. The repository's media folder is removed with the repository.
 *
 * The page never links the original address: a picture is shown only through the route of the app, by its id.
 */
final class ReadmeShowcase
{
    /**
     * The README file names tried, in this order.
     */
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md', 'README.markdown', 'README.MD'];

    /**
     * Collect the pictures and sections of the README at the repository's current commit.
     * Returns the manifest, or null when there is no clone, no commit or no README.
     *
     * @return array<string, mixed>|null
     */
    public function collect(Repository $repository): ?array
    {
        $sha = $repository->last_sha;
        $clone = new CloneFiles($repository);
        $readme = null;
        $readmePath = null;

        foreach (self::README_NAMES as $name) {
            $readme = $clone->read($name);

            if ($readme !== null) {
                $readmePath = $name;

                break;
            }
        }

        if ($sha === null || $readme === null || $readmePath === null) {
            return null;
        }

        $disk = Storage::disk('local');
        $root = $this->root($repository);
        $folder = "{$root}/{$sha}";
        $minimum = (int) config('gitchronicle.docs.pictures.min_pixels');
        $disk->deleteDirectory($folder);
        $disk->makeDirectory($folder);

        $stored = [];
        $idBySource = [];
        $hashes = [];
        $limit = (int) config('gitchronicle.docs.pictures.max_pictures');

        foreach (ReadmeImages::pictures($readme, $minimum) as $picture) {
            if (isset($idBySource[$picture['source']]) || count($stored) >= $limit) {
                continue;
            }

            $data = $this->bytes($repository, $picture['source']);
            $info = $data === null ? null : $this->describe($data, $minimum);

            if ($data === null || $info === null) {
                continue;
            }

            $hash = hash('sha256', $data);

            if (isset($hashes[$hash])) {
                $idBySource[$picture['source']] = $hashes[$hash];

                continue;
            }

            $id = 'readme-'.(count($stored) + 1);
            $file = "{$id}.{$info['extension']}";
            $disk->put("{$folder}/{$file}", $data);

            $hashes[$hash] = $id;
            $idBySource[$picture['source']] = $id;
            $stored[] = [
                'id' => $id,
                'file' => $file,
                'mime' => $info['mime'],
                'width' => $info['width'],
                'height' => $info['height'],
                'alt' => $picture['alt'],
                'section' => $picture['heading'],
            ];
        }

        $sections = [];

        foreach (ReadmeImages::sections($readme, $minimum) as $section) {
            $sections[] = [
                'heading' => $section['heading'],
                'level' => $section['level'],
                'text' => $section['text'],
                'pictures' => array_values(array_unique(array_filter(array_map(fn (string $source): ?string => $idBySource[$source] ?? null, $section['sources'])))),
            ];
        }

        $manifest = ['sha' => $sha, 'collected_at' => now()->toIso8601String(), 'pictures' => $stored, 'sections' => $sections];
        $disk->put("{$folder}/manifest.json", json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($disk->directories($root) as $old) {
            if (basename($old) !== $sha) {
                $disk->deleteDirectory($old);
            }
        }

        return $manifest;
    }

    /**
     * The manifest collected for the repository's current commit, or null (nothing collected yet, or for an older commit).
     *
     * @return array<string, mixed>|null
     */
    public function manifest(Repository $repository): ?array
    {
        if ($repository->last_sha === null) {
            return null;
        }

        $path = "{$this->root($repository)}/{$repository->last_sha}/manifest.json";
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $manifest = json_decode((string) $disk->get($path), true);

        return is_array($manifest) && ($manifest['sha'] ?? null) === $repository->last_sha ? $manifest : null;
    }

    /**
     * The stored file of a picture by its id ("readme-2"), with its type, or null. The id is looked up in the
     * manifest, so a request can never name a path.
     *
     * @return array{path: string, mime: string}|null
     */
    public function file(Repository $repository, string $id): ?array
    {
        foreach ($this->manifest($repository)['pictures'] ?? [] as $picture) {
            if ($picture['id'] === $id) {
                return ['path' => Storage::disk('local')->path("{$this->root($repository)}/{$repository->last_sha}/{$picture['file']}"), 'mime' => $picture['mime']];
            }
        }

        return null;
    }

    /**
     * The folder of the repository's collected pictures, relative to the "local" disk.
     */
    private function root(Repository $repository): string
    {
        return "{$repository->mediaDirectory()}/readme";
    }

    /**
     * The bytes of a README picture: a download for an https address, else a file of the clone.
     */
    private function bytes(Repository $repository, string $source): ?string
    {
        $limit = (int) config('gitchronicle.docs.pictures.max_bytes');

        if (preg_match('#\Ahttps?://#i', $source) === 1) {
            // A GitHub "blob" page is HTML; its "raw" address redirects to the file itself.
            $source = preg_replace('#\A(https://github\.com/[^/]+/[^/]+)/blob/#i', '$1/raw/', $source) ?? $source;

            return SafeDownload::image($source, (array) config('gitchronicle.docs.pictures.hosts'), $limit);
        }

        $path = rawurldecode(preg_replace('/[?#].*\z/', '', $source) ?? '');
        $root = realpath($repository->clonePath());
        $file = $root === false ? false : realpath($root.'/'.ltrim($path, '/'));

        if ($root === false || $file === false || ! str_starts_with($file, $root.'/') || ! is_file($file) || is_link($root.'/'.ltrim($path, '/')) || filesize($file) > $limit) {
            return null;
        }

        $data = file_get_contents($file);

        return $data === false ? null : $data;
    }

    /**
     * Type, size and file extension of a safe picture that is big enough; null for anything else.
     *
     * @return array{mime: string, width: ?int, height: ?int, extension: string}|null
     */
    private function describe(string $data, int $minimum): ?array
    {
        if (! SlideIdentity::isSafeImage($data)) {
            return null;
        }

        if (preg_match('/\A\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)?<svg\b/is', substr($data, 0, 2000)) === 1) {
            [$width, $height] = $this->svgSize($data);

            if ($width !== null && $height !== null && $width < $minimum && $height < $minimum) {
                return null;
            }

            return ['mime' => 'image/svg+xml', 'width' => $width, 'height' => $height, 'extension' => 'svg'];
        }

        $size = @getimagesizefromstring($data);

        if (! is_array($size) || ($size[0] < $minimum && $size[1] < $minimum) || min($size[0], $size[1]) < 48) {
            return null;
        }

        $extension = match ($size[2]) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
            default => null,
        };

        return $extension === null ? null : ['mime' => (string) image_type_to_mime_type($size[2]), 'width' => $size[0], 'height' => $size[1], 'extension' => $extension];
    }

    /**
     * The size of an SVG from its viewBox or its width and height; nulls when it states none.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function svgSize(string $svg): array
    {
        $tag = preg_match('/<svg\b[^>]*>/is', $svg, $match) === 1 ? $match[0] : '';

        if (preg_match('/\bviewBox\s*=\s*["\']\s*[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)/i', $tag, $box) === 1 && (float) $box[1] > 0 && (float) $box[2] > 0) {
            return [(int) round((float) $box[1]), (int) round((float) $box[2])];
        }

        $width = preg_match('/\bwidth\s*=\s*["\']([\d.]+)(?:px)?["\']/i', $tag, $w) === 1 ? (int) round((float) $w[1]) : null;
        $height = preg_match('/\bheight\s*=\s*["\']([\d.]+)(?:px)?["\']/i', $tag, $h) === 1 ? (int) round((float) $h[1]) : null;

        return [$width, $height];
    }
}
