<?php

namespace Tests\Unit\Media;

use Tests\TestCase;

/**
 * Every script of renderers/ that starts Chrome shows content that came from a stranger's repository (an image, a
 * diagram, a deck, the repository's GitHub page). Each one must intercept the page's requests, so the page can
 * only reach what it needs: never a file of this server, never an address inside the network.
 */
class ChromeScriptsAreLockedDownTest extends TestCase
{
    public function test_every_script_that_launches_chrome_intercepts_and_filters_the_requests_of_its_page(): void
    {
        $scripts = glob(base_path('renderers/{capture,slides}/*.mjs'), GLOB_BRACE);
        $launching = array_filter($scripts, fn (string $script): bool => str_contains((string) file_get_contents($script), 'puppeteer.launch'));

        $this->assertCount(4, $launching);

        foreach ($launching as $script) {
            $code = (string) file_get_contents($script);

            $this->assertStringContainsString('setRequestInterception(true)', $code, basename($script));
            $this->assertStringContainsString('request.abort()', $code, basename($script));
            $this->assertStringNotContainsString('--allow-file-access', $code, basename($script));
            $this->assertStringNotContainsString('--disable-web-security', $code, basename($script));
        }
    }

    public function test_the_github_capture_downloads_only_png_or_jpeg_bytes_up_to_a_size_limit(): void
    {
        $code = (string) file_get_contents(base_path('renderers/capture/github.mjs'));

        $this->assertStringContainsString('MAX_DOWNLOAD_BYTES', $code);
        $this->assertStringContainsString("redirect: 'manual'", $code);
        $this->assertStringContainsString('isPng || isJpeg', $code);
        $this->assertStringNotContainsString('arrayBuffer()', $code);
    }
}
