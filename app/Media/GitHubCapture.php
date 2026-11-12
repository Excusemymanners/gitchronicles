<?php

namespace App\Media;

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Photographs the repository's real GitHub page for its video (renderers/capture/github.mjs, with the
 * Chrome of this computer) and keeps the photo per commit: media/{id}/capture/{sha} on the "local" disk.
 *
 * - The photo of a commit is reused; $fresh (a regeneration asked by a person) takes a new one. Photos of
 *   older commits are deleted, so a video never shows a page older than the commit it presents.
 * - The script gets ToolEnvironment::isolated(): the worker's own variables (keys, passwords) are removed,
 *   and the script hands Chrome an even smaller environment. It contacts only GitHub and has its own time
 *   limit; a second limit here stops it if it hangs.
 * - Any failure gives PageCapture::missing() with the reason in Romanian: the video is then made from
 *   typographic scenes and says why. A photo is never invented and never borrowed from another commit.
 */
final class GitHubCapture
{
    /**
     * What the page says when the capture failed, by the script's error code.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        'unreachable' => 'GitHub nu a putut fi contactat când s-a generat video-ul.',
        'timeout' => 'Pagina de GitHub s-a încărcat prea încet când s-a generat video-ul.',
        'http_status' => 'GitHub nu a afișat pagina repository-ului (poate nu mai este public).',
        'unexpected_page' => 'Pagina de GitHub nu arăta ca pagina unui repository, așa că nu a fost folosită.',
        'browser_failed' => 'Chrome nu a pornit pe server, deci pagina de GitHub nu a putut fi fotografiată.',
    ];

    public const DISABLED_REASON = 'Fotografierea paginii de GitHub este oprită pe acest server.';

    public const FAILED_REASON = 'Pagina de GitHub nu a putut fi fotografiată.';

    /**
     * The version of the photo the composition expects (renderers/capture/github.mjs writes it). Version 2 is
     * taken at exactly the video's width (shown 1:1); an older photo is taken again instead of being used.
     */
    public const VERSION = 2;

    /**
     * The photo of the repository's page for its current commit: the stored one, or a new one.
     */
    public function capture(Repository $repository, bool $fresh = false): PageCapture
    {
        if (! config('gitchronicle.media.capture.enabled')) {
            return PageCapture::missing(self::DISABLED_REASON);
        }

        if (! $fresh) {
            $stored = $this->stored($repository);

            if ($stored->isAvailable()) {
                return $stored;
            }
        }

        $disk = Storage::disk('local');
        $folder = $this->folder($repository);
        $work = "{$folder}.new";
        $disk->deleteDirectory($work);
        $disk->makeDirectory($work);

        try {
            $result = Process::timeout((int) config('gitchronicle.media.capture.timeout'))
                ->env(ToolEnvironment::isolated())
                ->run([
                    config('gitchronicle.media.node_bin'),
                    rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/capture/github.mjs',
                    $repository->owner,
                    $repository->name,
                    $disk->path($work),
                    (string) config('gitchronicle.media.capture.max_height'),
                ]);
        } catch (Throwable $exception) {
            $disk->deleteDirectory($work);
            Log::info('The GitHub page capture did not finish.', ['repository_id' => $repository->id, 'message' => $exception->getMessage()]);

            return PageCapture::missing(self::REASONS['timeout']);
        }

        if ($result->failed() || $this->read($disk->path($work)) === null) {
            $disk->deleteDirectory($work);
            $error = json_decode(trim($result->output()), true)['error'] ?? null;
            Log::info('The GitHub page could not be captured.', ['repository_id' => $repository->id, 'error' => $error, 'output' => mb_substr($result->errorOutput(), -500)]);

            return PageCapture::missing(self::REASONS[$error] ?? self::FAILED_REASON);
        }

        // Only the new photo stays: the ones of older commits (and an older one of this commit) go.
        $disk->deleteDirectory($this->root($repository).'/'.basename($folder));

        foreach ($disk->directories($this->root($repository)) as $old) {
            if ($old !== $work) {
                $disk->deleteDirectory($old);
            }
        }

        File::move($disk->path($work), $disk->path($folder));

        return $this->stored($repository);
    }

    /**
     * The photo already taken for the repository's current commit (no browser, no network), or missing().
     */
    public function stored(Repository $repository): PageCapture
    {
        $directory = Storage::disk('local')->path($this->folder($repository));
        $data = $this->read($directory);

        return $data === null ? PageCapture::missing(self::FAILED_REASON) : PageCapture::from($directory, $data);
    }

    /**
     * The landmarks of a capture folder, or null when the folder is not a complete capture: the map must
     * be of the current VERSION, name the repository and every tile it lists must exist.
     *
     * @return array<string, mixed>|null
     */
    private function read(string $directory): ?array
    {
        if (! is_file("{$directory}/landmarks.json")) {
            return null;
        }

        $data = json_decode((string) file_get_contents("{$directory}/landmarks.json"), true);

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['landmarks']['name'] ?? null) || ! is_array($data['tiles'] ?? null) || $data['tiles'] === []) {
            return null;
        }

        foreach ($data['tiles'] as $tile) {
            if (! is_string($tile['file'] ?? null) || basename($tile['file']) !== $tile['file'] || ! is_file("{$directory}/{$tile['file']}")) {
                return null;
            }
        }

        return $data;
    }

    /**
     * The folder of all the captures of a repository, on the "local" disk.
     */
    private function root(Repository $repository): string
    {
        return $repository->mediaDirectory().'/capture';
    }

    /**
     * The folder of the capture of the current commit.
     */
    private function folder(Repository $repository): string
    {
        return $this->root($repository).'/'.($repository->last_sha ?? 'unknown');
    }
}
