<?php

namespace App\Documentation;

use App\Models\Document;
use App\Models\Repository;

/**
 * "README with pictures": the stored README text with the pictures of the documentation pages added as Markdown
 * images with relative links (images/...), and those picture files, so the pair works when dropped into a
 * repository. The stored text itself is not changed; pictures are inserted between its sections:
 *
 * - after the title: the capture of the repository's GitHub page (when one exists) and the languages/facts strip;
 * - after the "How it solves it" section (else after the first section): the architecture diagram, drawn as a PNG;
 * - at the end: "From the project's own README", the README's own pictures with their captions.
 *
 * A picture whose source does not exist (no capture, no pictures in the README, no Chrome for the diagram) is
 * left out. Every picture has alt text and a caption saying what it is and where it comes from.
 */
class ReadmeWithPictures
{
    public function __construct(private DocumentVisuals $visuals, private ReadmeShowcase $showcase, private DiagramPng $diagramPng) {}

    /**
     * The Markdown and the files of the archive.
     *
     * @return array{markdown: string, files: array<string, string>} files: path in the archive => bytes
     */
    public function build(Repository $repository, Document $readme): array
    {
        $locale = (string) config('gitchronicle.content_language');
        $sha = substr((string) $repository->last_sha, 0, 7);
        $files = [];
        $afterTitle = [];
        $afterArchitecture = [];
        $gallery = [];

        $capture = $this->visuals->captureFile($repository);

        if ($capture !== null) {
            $extension = pathinfo($capture['path'], PATHINFO_EXTENSION);
            $files["images/github-page.{$extension}"] = (string) file_get_contents($capture['path']);
            $caption = __('visuals.capture_caption', ['date' => $capture['captured_at'] === null ? '' : substr($capture['captured_at'], 0, 10)], $locale);
            $afterTitle[] = $this->figure(__('visuals.capture_alt', ['repository' => "{$repository->owner}/{$repository->name}"], $locale), "images/github-page.{$extension}", rtrim($caption, ' '));
        }

        $facts = $this->visuals->facts($repository);

        if ($facts !== null) {
            $files['images/facts.svg'] = FactsStripSvg::draw($facts, [
                'files' => __('visuals.files', [], $locale), 'lines' => __('visuals.lines', [], $locale),
                'modules' => __('visuals.modules', [], $locale), 'commits' => __('visuals.commits', [], $locale),
            ]);
            $afterTitle[] = $this->figure(__('visuals.facts_alt', [], $locale), 'images/facts.svg', __('visuals.facts_caption', ['sha' => $sha], $locale));
        }

        $architecture = $this->visuals->architecture($repository);
        $png = $architecture === null ? null : $this->diagramPng->render($architecture['mermaid']);

        if ($architecture !== null && $png !== null) {
            $files['images/architecture.png'] = $png;
            $afterArchitecture[] = $this->figure($architecture['title'], 'images/architecture.png', __('visuals.drawn_caption', ['sha' => $sha], $locale));
        }

        $manifest = $this->showcase->manifest($repository) ?? [];
        $sections = ReadmeDescription::sections($manifest, $readme->markdown, fn (string $file): string => "images/{$file}");
        $used = array_merge(...array_column($sections, 'pictures') ?: [[]]);

        foreach ($manifest['pictures'] ?? [] as $picture) {
            $stored = $this->showcase->file($repository, $picture['id']);

            if ($stored === null || ! is_file($stored['path'])) {
                continue;
            }

            $name = "images/{$picture['file']}";
            $files[$name] = (string) file_get_contents($stored['path']);
            $alt = $picture['alt'] !== '' ? $picture['alt'] : __('visuals.picture_alt', ['number' => count($gallery) + 1], $locale);
            $caption = $picture['section'] !== null
                ? __('visuals.readme_caption_section', ['section' => $picture['section']], $locale)
                : __('visuals.readme_caption', [], $locale);

            if (! in_array($picture['id'], $used, true)) {
                $gallery[] = $this->figure($alt, $name, $caption);
            }
        }

        $markdown = $this->insert($readme->markdown, $afterTitle, $afterArchitecture);

        if ($sections !== [] || $gallery !== []) {
            $part = '## '.__('visuals.gallery_title', [], $locale)."\n\n*".__('visuals.description_caption', ['sha' => $sha], $locale).'*';

            foreach ($sections as $section) {
                $part .= "\n\n### ".($section['heading'] ?? __('visuals.intro_heading', [], $locale))."\n\n".$section['markdown'];
            }

            $markdown = rtrim($markdown)."\n\n".$part.($gallery === [] ? '' : "\n\n".implode("\n\n", $gallery))."\n";
        }

        return ['markdown' => $markdown, 'files' => $files];
    }

    /**
     * An image with its caption under it.
     */
    private function figure(string $alt, string $path, string $caption): string
    {
        $alt = trim(str_replace(['[', ']', "\n"], ['(', ')', ' '], $alt));

        return "![{$alt}]({$path})\n*{$caption}*";
    }

    /**
     * Put the blocks into the text: $afterTitle after the "# Title" line, $afterArchitecture at the end of the
     * "How it solves it" section (else of the first "##" section). Headings inside code blocks are not headings.
     *
     * @param  list<string>  $afterTitle
     * @param  list<string>  $afterArchitecture
     */
    private function insert(string $markdown, array $afterTitle, array $afterArchitecture): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $inCode = false;
        $title = null;
        $sections = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/\A\s{0,3}(```|~~~)/', $line) === 1) {
                $inCode = ! $inCode;
            }

            if ($inCode || preg_match('/\A(#{1,2})\s+(.+?)\s*#*\s*\z/u', $line, $heading) !== 1) {
                continue;
            }

            if (strlen($heading[1]) === 1 && $title === null) {
                $title = $index;
            } elseif (strlen($heading[1]) === 2) {
                $sections[] = ['line' => $index, 'heading' => $heading[2]];
            }
        }

        $at = [];

        if ($afterTitle !== []) {
            $at[$title === null ? -1 : $title] = $afterTitle;
        }

        if ($afterArchitecture !== [] && $sections !== []) {
            $target = 0;

            foreach ($sections as $position => $section) {
                if (preg_match('/how it solves it|architecture|how it is built|how it works/i', $section['heading']) === 1) {
                    $target = $position;

                    break;
                }
            }

            $end = ($sections[$target + 1]['line'] ?? count($lines)) - 1;
            $at[$end] = [...($at[$end] ?? []), ...$afterArchitecture];
        }

        $result = [];

        if (isset($at[-1])) {
            $result[] = implode("\n\n", $at[-1]);
            $result[] = '';
        }

        foreach ($lines as $index => $line) {
            $result[] = $line;

            if (isset($at[$index])) {
                $result[] = '';
                $result[] = implode("\n\n", $at[$index]);
                $result[] = '';
            }
        }

        return implode("\n", $result);
    }
}
