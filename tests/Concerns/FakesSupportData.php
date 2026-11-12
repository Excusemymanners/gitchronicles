<?php

namespace Tests\Concerns;

use App\Models\Repository;
use App\Models\RepositoryFile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Stands in for the public sources of the version check (endoflife.date, Node.js's release index, php.net),
 * so a test never reaches the network. The answers are trimmed copies of the real ones, recorded on
 * 2026-11-12 (tests/Fixtures/consulting/support); a product without a fixture answers 404, like the real
 * API does for a product it does not know.
 */
trait FakesSupportData
{
    /**
     * Fake the three sources; `$unreachable` makes every request fail as if the server were offline.
     */
    protected function fakeSupportData(bool $unreachable = false): void
    {
        Cache::flush();

        Http::fake(function (Request $request) use ($unreachable) {
            if ($unreachable) {
                throw new ConnectionException('offline');
            }

            $url = $request->url();

            if (preg_match('#endoflife\.date/api/v1/products/([^/]+)/#', $url, $matches) === 1) {
                $path = base_path("tests/Fixtures/consulting/support/endoflife-{$matches[1]}.json");

                return is_file($path) ? Http::response((string) file_get_contents($path)) : Http::response('{}', 404);
            }

            if (str_contains($url, 'nodejs.org/dist/index.json')) {
                return Http::response((string) file_get_contents(base_path('tests/Fixtures/consulting/support/node-index.json')));
            }

            if (str_contains($url, 'php.net/releases')) {
                return Http::response((string) file_get_contents(base_path('tests/Fixtures/consulting/support/php-8.3.json')));
            }

            return Http::response('{}', 404);
        });
    }

    /**
     * Write files into the repository's clone (on the fake disk) and record them as analyzed files.
     *
     * @param  array<string, string>  $files  path => contents
     */
    protected function writeClone(Repository $repository, array $files): void
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$repository->clonePath()}/{$path}"));
            File::put("{$repository->clonePath()}/{$path}", $contents);
            RepositoryFile::factory()->for($repository)->create(['path' => $path, 'language' => null]);
        }
    }
}
