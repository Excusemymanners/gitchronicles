<?php

namespace App\Documentation;

use App\Media\ToolEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Draws a stored Mermaid diagram as a PNG with the renderer the slides use (renderers/slides/diagram.mjs, Chrome
 * and the local Mermaid, no network), for the README archive. Without Node, Chrome or the installed tools it
 * gives null and the archive simply has no architecture picture: a picture is never faked.
 */
class DiagramPng
{
    /**
     * Seconds the renderer may run.
     */
    private const TIMEOUT_SECONDS = 90;

    /**
     * The bytes of the PNG, or null when the diagram cannot be drawn.
     */
    public function render(string $mermaid): ?string
    {
        $script = rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/slides/diagram.mjs';

        if (! ToolEnvironment::isInstalled() || ! is_file($script)) {
            return null;
        }

        $folder = storage_path('app/private/tmp/diagram-'.bin2hex(random_bytes(6)));

        try {
            File::ensureDirectoryExists($folder);
            File::put("{$folder}/diagram.mmd", $mermaid);

            $result = Process::timeout(self::TIMEOUT_SECONDS)->env(ToolEnvironment::variables())->run([
                config('gitchronicle.media.node_bin'), $script, "{$folder}/diagram.mmd", "{$folder}/diagram.png", resource_path('js/lib/diagram-icons.json'),
            ]);

            if ($result->failed() || ! is_file("{$folder}/diagram.png")) {
                Log::info('A diagram could not be drawn for the README archive.', ['output' => mb_substr($result->errorOutput(), -500)]);

                return null;
            }

            $png = File::get("{$folder}/diagram.png");

            return str_starts_with($png, "\x89PNG") ? $png : null;
        } catch (Throwable $exception) {
            Log::info('A diagram could not be drawn for the README archive.', ['message' => $exception->getMessage()]);

            return null;
        } finally {
            File::deleteDirectory($folder);
        }
    }
}
