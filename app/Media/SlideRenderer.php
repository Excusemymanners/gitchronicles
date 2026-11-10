<?php

namespace App\Media;

use App\Models\Repository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Makes the slides of a repository with Marp CLI, in a work folder (media/{id}/work-slides on the
 * "local" disk) that is deleted at the end:
 *
 * 1. the theme (renderers/slides/theme.css) with every font file replaced by its base64 content;
 * 2. the architecture diagram drawn as a PNG (renderers/slides/diagram.mjs: Marp cannot draw Mermaid);
 *    if drawing fails, the deck is made without that slide;
 * 3. the Markdown of the deck (SlideDeck), with the logo and the diagram as data URIs;
 * 4. Marp, once for the PDF and once for the PPTX, with this computer's Chrome.
 *
 * Marp is always called with --no-stdin (a queue worker's open standard input would make it wait forever),
 * with the Markdown file before --theme-set (which would otherwise take it as a theme) and without
 * --allow-local-files: everything the deck needs is inside its Markdown and theme.
 */
final class SlideRenderer
{
    /**
     * The longest run of one tool, in seconds.
     */
    public const TOOL_TIMEOUT_SECONDS = 120;

    /**
     * Render the slides and return what the job saves.
     *
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @param  list<array<string, mixed>>  $scenes  the script's scenes
     * @return array{files: array{pdf: string, pptx: string}, size_bytes: int, slides: list<string>, render_ms: int}
     *
     * @throws RuntimeException when Marp fails
     */
    public function render(Repository $repository, array $facts, array $scenes): array
    {
        $startedAt = microtime(true);
        $disk = Storage::disk('local');
        $directory = $repository->mediaDirectory();
        $work = "{$directory}/work-slides";

        $disk->deleteDirectory($work);
        $disk->makeDirectory($work);
        $workPath = $disk->path($work);

        try {
            File::put("{$workPath}/theme.css", $this->themeWithFonts());

            $deck = SlideDeck::build(
                $facts,
                $scenes,
                self::dataUri(public_path('logo-256.png'), 'image/png'),
                $this->drawDiagram($facts['diagram'] ?? null, $workPath),
            );
            File::put("{$workPath}/deck.md", $deck['markdown']);

            foreach (['pdf' => ['--pdf', '--pdf-outlines'], 'pptx' => ['--pptx']] as $format => $options) {
                $this->run([
                    config('gitchronicle.media.node_bin'), $this->renderers('node_modules/@marp-team/marp-cli/marp-cli.js'),
                    '--no-stdin', "{$workPath}/deck.md",
                    '--no-config-file',
                    '--theme-set', "{$workPath}/theme.css",
                    '--browser', 'chrome', '--browser-path', (string) config('gitchronicle.media.browser_path'),
                    ...$options,
                    '-o', "{$workPath}/slides.{$format}",
                ], "marp {$format}");

                File::move("{$workPath}/slides.{$format}", $disk->path("{$directory}/slides.{$format}"));
            }

            return [
                'files' => ['pdf' => "{$directory}/slides.pdf", 'pptx' => "{$directory}/slides.pptx"],
                'size_bytes' => (int) $disk->size("{$directory}/slides.pdf"),
                'slides' => $deck['titles'],
                'render_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } finally {
            $disk->deleteDirectory($work);
        }
    }

    /**
     * The theme with every url("fonts/….woff2") replaced by the font file itself, in base64.
     */
    private function themeWithFonts(): string
    {
        $fonts = $this->renderers('video/assets/fonts');

        return preg_replace_callback(
            '/url\("fonts\/([a-z0-9-]+\.woff2)"\)/',
            fn (array $match): string => 'url("'.self::dataUri("{$fonts}/{$match[1]}", 'font/woff2').'")',
            File::get($this->renderers('slides/theme.css')),
        ) ?? '';
    }

    /**
     * Draw the diagram's Mermaid code as a PNG and return it as a data URI with its size, or null when
     * there is no diagram or it could not be drawn (the deck then has no „Arhitectura” slide).
     *
     * @param  array{type: string, title: string, caption: string|null, mermaid: string}|null  $diagram
     * @return array{uri: string, width: int, height: int}|null
     */
    private function drawDiagram(?array $diagram, string $workPath): ?array
    {
        if ($diagram === null) {
            return null;
        }

        File::put("{$workPath}/diagram.mmd", $diagram['mermaid']);

        try {
            $this->run([
                config('gitchronicle.media.node_bin'), $this->renderers('slides/diagram.mjs'),
                "{$workPath}/diagram.mmd", "{$workPath}/diagram.png", resource_path('js/lib/diagram-icons.json'),
            ], 'diagram');
        } catch (RuntimeException) {
            return null;
        }

        $size = @getimagesize("{$workPath}/diagram.png");

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return ['uri' => self::dataUri("{$workPath}/diagram.png", 'image/png'), 'width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Run one tool with the isolated environment (ToolEnvironment); log its output and throw when it fails.
     *
     * @param  list<string>  $command
     */
    private function run(array $command, string $tool): void
    {
        $result = Process::timeout(self::TOOL_TIMEOUT_SECONDS)->env(ToolEnvironment::variables())->run($command);

        if ($result->failed()) {
            Log::warning("Media tool failed: {$tool}.", [
                'exit_code' => $result->exitCode(),
                'output' => mb_substr($result->output(), -2000),
                'error_output' => mb_substr($result->errorOutput(), -2000),
            ]);

            throw new RuntimeException("{$tool} failed with exit code {$result->exitCode()}.");
        }
    }

    /**
     * A file as a data URI: "data:image/png;base64,…".
     */
    private static function dataUri(string $path, string $mimeType): string
    {
        return "data:{$mimeType};base64,".base64_encode(File::get($path));
    }

    /**
     * A path inside renderers/.
     */
    private function renderers(string $path): string
    {
        return rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/'.$path;
    }
}
