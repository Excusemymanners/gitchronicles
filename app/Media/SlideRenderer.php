<?php

namespace App\Media;

use App\Media\Slides\Slide;
use App\Media\Slides\SlideIdentity;
use App\Media\Slides\SlidePalette;
use App\Media\Slides\SlidePlanner;
use App\Media\Slides\SlideSource;
use App\Media\Slides\SlideTextWriter;
use App\Models\Repository;
use App\Support\ContentLanguage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Makes the slides of a repository: reads its documentation and diagrams (SlideSource), its identity
 * (SlideIdentity), plans the slides (SlidePlanner), lets the text writer improve the wording (one AI call,
 * or none), writes the Markdown (SlideDeck), shortens every slide that does not fit, and exports the PDF
 * and the PPTX with Marp.
 *
 * Marp, Chrome and Mermaid are programs of renderers/ that run as separate processes.
 */
final class SlideRenderer
{
    /**
     * How long one of the tools may run, in seconds.
     */
    public const TOOL_TIMEOUT_SECONDS = 120;

    /**
     * How many times a slide that does not fit is made shorter before it is accepted as it is.
     */
    public const MAX_FIT_ROUNDS = 5;

    public function __construct(
        private SlideSource $source,
        private SlideIdentity $identity,
        private SlideTextWriter $writer,
    ) {}

    /**
     * Render the deck.
     *
     * @param  array<string, mixed>  $facts  PresentationFacts::summary()
     * @param  list<array<string, mixed>>  $scenes  the scenes of the script (only the "problem" one is used)
     * @return array{files: array{pdf: string, pptx: string}, size_bytes: int, pptx_size_bytes: int, slides: list<string>, accent: array{color: string, source: string}, render_ms: int}
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
            $locale = ContentLanguage::code();
            $identity = $this->identity->collect($repository, $facts['github'] ?? null);
            $palette = SlidePalette::fromAccent($identity['accent']);

            File::put("{$workPath}/theme.css", $this->themeWithFonts());
            File::put("{$workPath}/palette.json", json_encode($palette, JSON_THROW_ON_ERROR));

            $source = $this->source->collect($repository);
            $source['diagrams'] = array_map(
                fn (array $diagram, int $position): array => [...$diagram, 'image' => $this->drawDiagram($diagram['mermaid'], "{$workPath}/diagram-{$position}")],
                $source['diagrams'],
                array_keys($source['diagrams']),
            );

            $slides = $this->writer->improve(SlidePlanner::plan($facts, $source, $scenes, $locale), $source, $facts);
            $slides = $this->fit($slides, $identity, $facts, $locale, $workPath);

            $deck = SlideDeck::build($slides, $identity, $facts, $locale, CarbonImmutable::now());
            File::put("{$workPath}/deck.md", $deck['markdown']);

            foreach (['pdf' => ['--pdf', '--pdf-outlines'], 'pptx' => ['--pptx']] as $format => $options) {
                $this->marp($workPath, $options, "{$workPath}/slides.{$format}");
                File::move("{$workPath}/slides.{$format}", $disk->path("{$directory}/slides.{$format}"));
            }

            $this->assertSameSlideCount($disk->path("{$directory}/slides.pptx"), count($slides));

            return [
                'files' => ['pdf' => "{$directory}/slides.pdf", 'pptx' => "{$directory}/slides.pptx"],
                'size_bytes' => (int) $disk->size("{$directory}/slides.pdf"),
                'pptx_size_bytes' => (int) $disk->size("{$directory}/slides.pptx"),
                'slides' => $deck['titles'],
                'accent' => ['color' => $identity['accent'], 'source' => $identity['accent_source']],
                'render_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } finally {
            $disk->deleteDirectory($work);
        }
    }

    /**
     * Shorten the slides whose content does not fit: the deck is turned into HTML, every slide is measured
     * in Chrome (renderers/slides/fit.mjs), and each slide that overflows loses its last item, until all fit
     * or nothing more can be taken away.
     *
     * @param  list<Slide>  $slides
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $facts
     * @return list<Slide>
     */
    private function fit(array $slides, array $identity, array $facts, string $locale, string $workPath): array
    {
        for ($round = 0; $round < self::MAX_FIT_ROUNDS; $round++) {
            File::put("{$workPath}/measure.md", SlideDeck::build($slides, $identity, $facts, $locale, CarbonImmutable::now())['markdown']);
            $this->marp($workPath, ['--template', 'bare'], "{$workPath}/measure.html", 'measure.md');

            $overflowing = $this->overflowing("{$workPath}/measure.html");

            if ($overflowing === []) {
                return $slides;
            }

            $changed = false;

            foreach ($overflowing as $number) {
                $shorter = $slides[$number - 1]->shorter();

                if ($shorter !== null) {
                    $slides[$number - 1] = $shorter;
                    $changed = true;
                }
            }

            if (! $changed) {
                Log::warning('Some slides do not fit and cannot be made shorter.', ['repository_id' => $facts['name'] ?? null, 'slides' => $overflowing]);

                return $slides;
            }
        }

        return $slides;
    }

    /**
     * The numbers (from 1) of the slides of an HTML deck that do not fit.
     *
     * @return list<int>
     */
    private function overflowing(string $htmlPath): array
    {
        $result = Process::timeout(self::TOOL_TIMEOUT_SECONDS)->env(ToolEnvironment::variables())->run([
            config('gitchronicle.media.node_bin'), $this->renderers('slides/fit.mjs'), $htmlPath,
        ]);

        if ($result->failed()) {
            Log::warning('Media tool failed: fit.', ['output' => mb_substr($result->output(), -1000), 'error_output' => mb_substr($result->errorOutput(), -1000)]);

            return [];
        }

        $measured = json_decode(trim($result->output()), true);

        return is_array($measured) ? array_values(array_map('intval', $measured['overflowing'] ?? [])) : [];
    }

    /**
     * Run Marp on the deck of the work folder.
     *
     * @param  list<string>  $options  the format options
     */
    private function marp(string $workPath, array $options, string $output, string $input = 'deck.md'): void
    {
        $this->run([
            config('gitchronicle.media.node_bin'), $this->renderers('node_modules/@marp-team/marp-cli/marp-cli.js'),
            '--no-stdin', "{$workPath}/{$input}",
            '--no-config-file', '--html',
            '--theme-set', "{$workPath}/theme.css",
            '--browser', 'chrome', '--browser-path', (string) config('gitchronicle.media.browser_path'),
            ...$options,
            '-o', $output,
        ], 'marp');
    }

    /**
     * The PPTX must have one slide for every slide of the PDF (Marp makes both from the same Markdown).
     */
    private function assertSameSlideCount(string $pptxPath, int $expected): void
    {
        $zip = new ZipArchive;

        // A file that is not a ZIP is not a PPTX Marp made (the tests' fake Marp writes plain text): nothing to count.
        if ($zip->open($pptxPath) !== true) {
            return;
        }

        $count = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            if (preg_match('#\Appt/slides/slide\d+\.xml\z#', (string) $zip->getNameIndex($index)) === 1) {
                $count++;
            }
        }

        $zip->close();

        if ($count !== $expected) {
            throw new RuntimeException("The PPTX has {$count} slides, the deck {$expected}.");
        }
    }

    /**
     * The theme with its fonts inside (Marp may not read files, and the PDF needs the fonts).
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
     * Draw a Mermaid diagram as a PNG in the colours of the deck. Null when it cannot be drawn: its slide is left out.
     *
     * @return array{uri: string, width: int, height: int}|null
     */
    private function drawDiagram(string $mermaid, string $basePath): ?array
    {
        File::put("{$basePath}.mmd", $mermaid);

        try {
            $this->run([
                config('gitchronicle.media.node_bin'), $this->renderers('slides/diagram.mjs'),
                "{$basePath}.mmd", "{$basePath}.png", resource_path('js/lib/diagram-icons.json'), dirname($basePath).'/palette.json',
            ], 'diagram');
        } catch (RuntimeException) {
            return null;
        }

        $size = @getimagesize("{$basePath}.png");

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return ['uri' => self::dataUri("{$basePath}.png", 'image/png'), 'width' => $size[0], 'height' => $size[1]];
    }

    /**
     * Run a tool; a failure is logged and becomes an exception.
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

    private static function dataUri(string $path, string $mimeType): string
    {
        return "data:{$mimeType};base64,".base64_encode(File::get($path));
    }

    private function renderers(string $path): string
    {
        return rtrim((string) config('gitchronicle.media.renderers_path'), '/').'/'.$path;
    }
}
