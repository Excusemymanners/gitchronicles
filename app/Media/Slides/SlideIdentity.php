<?php

namespace App\Media\Slides;

use App\Documentation\CloneFiles;
use App\Media\ToolEnvironment;
use App\Models\Repository;
use App\Support\SafeDownload;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * What makes a deck belong to ITS repository: the owner's avatar, the project's own logo (the first real
 * image of its README) and an accent colour taken from the logo, else from the avatar, else from the
 * main language, else from the repository's name. Images are downloaded once, kept in the media folder
 * and embedded in the deck as data URIs; they are never linked from the deck.
 */
final class SlideIdentity
{
    private const MAX_IMAGE_BYTES = 2_000_000;

    private const MAX_SVG_BYTES = 500_000;

    /**
     * The most pixels a picture may have (about 6000 x 4000): more is not a logo.
     */
    private const MAX_PIXELS = 25_000_000;

    private const BADGE_HOSTS = ['shields.io', 'badge', 'travis-ci', 'codecov', 'coveralls', 'circleci', 'github.com/.*/workflows', 'sonarcloud', 'badgen.net', 'img.youtube.com'];

    /**
     * Collect the identity of a repository.
     *
     * @param  array<string, mixed>|null  $github  GitHubMetadata::for()
     * @return array{avatar: ?string, logo: ?array{uri: string, ratio: float}, accent: string, accent_source: string}
     */
    public function collect(Repository $repository, ?array $github): array
    {
        $avatar = $this->avatar($repository, $github);
        $logo = $this->logo($repository);

        [$accent, $source] = $this->accent($repository, $github, $logo, $avatar);

        return [
            'avatar' => $avatar === null ? null : self::dataUri($avatar['data'], $avatar['mime']),
            'logo' => $logo === null ? null : ['uri' => self::dataUri($logo['data'], $logo['mime']), 'ratio' => $logo['ratio']],
            'accent' => $accent,
            'accent_source' => $source,
        ];
    }

    /**
     * The first image of the README that is a logo and not a badge, as a local file of the clone or an
     * address to download. A picture named "logo" or "icon" wins over the first picture.
     *
     * @return list<string> the image sources, best first
     */
    public static function logoCandidates(string $readme): array
    {
        $lines = array_slice(preg_split('/\R/u', $readme) ?: [], 0, 60);
        $found = [];

        foreach ($lines as $position => $line) {
            preg_match_all('/!\[([^\]]*)\]\(\s*<?([^)\s>]+)|<img\b[^>]*?\bsrc=["\']([^"\']+)["\'][^>]*?(?:\balt=["\']([^"\']*)["\'])?[^>]*>/i', $line, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $source = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
                $alt = ($match[1] ?? '').($match[4] ?? '');

                if ($source === '' || self::isBadge($source) || preg_match('/\.(png|jpe?g|gif|webp|svg)(\?.*)?\z/i', $source) !== 1) {
                    continue;
                }

                $found[] = ['source' => $source, 'named' => preg_match('/logo|icon|brand/i', $source.' '.$alt) === 1, 'position' => $position];
            }
        }

        $named = array_values(array_filter($found, fn (array $image): bool => $image['named']));
        $early = array_values(array_filter($found, fn (array $image): bool => $image['position'] < 25));

        return array_values(array_unique(array_column([...$named, ...$early], 'source')));
    }

    private static function isBadge(string $source): bool
    {
        foreach (self::BADGE_HOSTS as $host) {
            if (preg_match('#'.str_replace('#', '\\#', $host).'#i', $source) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $github
     * @return array{data: string, mime: string}|null
     */
    private function avatar(Repository $repository, ?array $github): ?array
    {
        $path = "{$repository->mediaDirectory()}/identity/avatar.bin";
        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            $url = $github['avatar_url'] ?? 'https://github.com/'.rawurlencode($repository->owner).'.png?size=240';
            $data = self::download($url);

            if ($data === null) {
                return null;
            }

            $disk->put($path, $data);
        }

        $data = $disk->get($path);
        $mime = $data === null || ! self::isSafeImage($data) ? null : self::imageType($data);

        return $data === null || $mime === null ? null : ['data' => $data, 'mime' => $mime];
    }

    /**
     * @return array{data: string, mime: string, ratio: float}|null
     */
    private function logo(Repository $repository): ?array
    {
        $disk = Storage::disk('local');
        $path = "{$repository->mediaDirectory()}/identity/logo.bin";

        if (! $disk->exists($path)) {
            $readme = $this->readme($repository);
            $data = null;

            foreach ($readme === null ? [] : array_slice(self::logoCandidates($readme), 0, 3) as $source) {
                $data = $this->logoData($repository, $source);

                if ($data !== null) {
                    break;
                }
            }

            if ($data === null) {
                return null;
            }

            $disk->put($path, $data);
        }

        $data = $disk->get($path);
        $mime = $data === null || ! self::isSafeImage($data) ? null : self::imageType($data);

        if ($data === null || $mime === null) {
            return null;
        }

        $size = $mime === 'image/svg+xml' ? self::svgSize($data) : @getimagesizefromstring($data);
        $ratio = $size === false || $size === null || ($size[1] ?? 0) < 1 ? 1.0 : $size[0] / $size[1];

        return ['data' => $data, 'mime' => $mime, 'ratio' => $ratio];
    }

    private function readme(Repository $repository): ?string
    {
        $clone = new CloneFiles($repository);

        foreach (['README.md', 'readme.md', 'Readme.md', 'README.markdown'] as $name) {
            $text = $clone->read($name);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The bytes of a README image: a file of the clone, or a download from GitHub (never another host's address).
     */
    private function logoData(Repository $repository, string $source): ?string
    {
        if (preg_match('#\Ahttps://#i', $source) === 1) {
            return self::download($source);
        }

        $root = realpath($repository->clonePath());
        $file = $root === false ? false : realpath($root.'/'.ltrim(preg_replace('/[?#].*\z/', '', $source) ?? '', './'));

        if ($root === false || $file === false || ! str_starts_with($file, $root.'/') || ! is_file($file) || filesize($file) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $data = file_get_contents($file);

        return $data !== false && self::isSafeImage($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>|null  $github
     * @param  array{data: string, mime: string, ratio: float}|null  $logo
     * @param  array{data: string, mime: string}|null  $avatar
     * @return array{string, string} the colour and where it comes from
     */
    private function accent(Repository $repository, ?array $github, ?array $logo, ?array $avatar): array
    {
        $fromLogo = $logo === null ? null : $this->dominantColor($repository, 'logo');

        if ($fromLogo !== null) {
            return [$fromLogo, 'logo'];
        }

        $fromAvatar = $avatar === null ? null : $this->dominantColor($repository, 'avatar');

        if ($fromAvatar !== null) {
            return [$fromAvatar, 'avatar'];
        }

        $fromLanguage = LanguageColors::for($github['language'] ?? null);

        if ($fromLanguage !== null) {
            return [$fromLanguage, 'language'];
        }

        // Nothing to take a colour from: a hue made from the name, so the same repository always gets the same one.
        $hue = hexdec(substr(md5(strtolower("{$repository->owner}/{$repository->name}")), 0, 4)) % 360;

        return [SlidePalette::fromHsl((float) $hue, 0.6, 0.45), 'name'];
    }

    /**
     * The dominant colour of a stored image ("logo" or "avatar"), read with Chrome; null when Chrome fails.
     */
    private function dominantColor(Repository $repository, string $name): ?string
    {
        $disk = Storage::disk('local');
        $path = "{$repository->mediaDirectory()}/identity/{$name}.bin";
        $data = $disk->get($path);
        $mime = $data === null ? null : self::imageType($data);

        if ($mime === null) {
            return null;
        }

        $result = Process::timeout(60)->env(ToolEnvironment::variables())->run([
            config('gitchronicle.media.node_bin'),
            rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/slides/pixels.mjs',
            $disk->path($path), $mime,
        ]);

        $pixels = $result->successful() ? json_decode($result->output(), true) : null;

        return is_array($pixels) ? DominantColor::of($pixels) : null;
    }

    /**
     * An image from the internet: only https addresses of GitHub's own hosts (config gitchronicle.media.image_hosts),
     * never an address inside our network, with a size limit, and only if the bytes really are a safe image.
     */
    private static function download(string $url): ?string
    {
        $body = SafeDownload::image($url, (array) config('gitchronicle.media.image_hosts'), self::MAX_IMAGE_BYTES);

        return $body !== null && self::isSafeImage($body) ? $body : null;
    }

    /**
     * Whether the bytes are an image that is safe to decode in Chrome and to embed in a deck: a known type, a
     * raster picture of a sane size (a few KB of PNG can claim 60,000 x 60,000 pixels and fill the memory
     * when decoded), and an SVG without scripts, embedded pages, entities or links to other files.
     */
    public static function isSafeImage(string $data): bool
    {
        $mime = self::imageType($data);

        if ($mime === null) {
            return false;
        }

        if ($mime === 'image/svg+xml') {
            return strlen($data) <= self::MAX_SVG_BYTES
                && preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[|<script|<foreignObject|<iframe|<embed|<object|<link|\bon[a-z]+\s*=|(?:href|src)\s*=\s*["\']\s*(?!#|data:image\/)[^"\']/i', $data) !== 1;
        }

        $size = @getimagesizefromstring($data);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= self::MAX_PIXELS;
    }

    /**
     * The MIME type of an image from its first bytes (a file name or a header is not trusted), or null.
     */
    private static function imageType(string $data): ?string
    {
        return match (true) {
            str_starts_with($data, "\x89PNG") => 'image/png',
            str_starts_with($data, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($data, 'GIF8') => 'image/gif',
            str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP' => 'image/webp',
            preg_match('/\A\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)?<svg\b/is', substr($data, 0, 2000)) === 1 => 'image/svg+xml',
            default => null,
        };
    }

    /**
     * The width and height of an SVG, from its viewBox or its width and height attributes.
     *
     * @return array{float, float}|null
     */
    private static function svgSize(string $svg): ?array
    {
        if (preg_match('/viewBox=["\']\s*[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)/i', $svg, $match) === 1 && (float) $match[2] > 0) {
            return [(float) $match[1], (float) $match[2]];
        }

        if (preg_match('/<svg[^>]*\bwidth=["\']([\d.]+)[^"\']*["\'][^>]*\bheight=["\']([\d.]+)/i', $svg, $match) === 1 && (float) $match[2] > 0) {
            return [(float) $match[1], (float) $match[2]];
        }

        return null;
    }

    private static function dataUri(string $data, string $mime): string
    {
        return "data:{$mime};base64,".base64_encode($data);
    }
}
