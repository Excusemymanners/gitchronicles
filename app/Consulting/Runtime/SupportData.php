<?php

namespace App\Consulting\Runtime;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Live public data about support of runtimes and frameworks, fetched at scan time and kept for 24 hours
 * (a failed answer is never cached):
 *
 * - endoflife.date (API v1, https://endoflife.date/docs/api/v1/): for a product, every release line with
 *   its end-of-life date, the date security-only support starts, whether it is still maintained and its newest release;
 * - Node.js's release index (https://nodejs.org/dist/index.json), which flags security releases;
 * - php.net's release list (https://www.php.net/releases/index.php?json&version=8.3&max=100), which tags security releases.
 *
 * Only the product name is in the URL: nothing from the repository leaves the server. The User-Agent is a
 * generic client name.
 */
final class SupportData
{
    private const USER_AGENT = 'GitChronicle-version-check';

    /**
     * The release lines of a product from endoflife.date, newest first, and when they were fetched.
     *
     * @return array{fetched_at: string, label: string, url: string, releases: list<array{name: string, label: string, is_eol: bool, eol_from: string|null, is_eoas: bool, eoas_from: string|null, is_maintained: bool, is_lts: bool, latest: string|null, latest_date: string|null}>}
     *
     * @throws SupportDataException
     */
    public function lifecycle(string $slug): array
    {
        return $this->cached("lifecycle:{$slug}", function () use ($slug): array {
            $answer = $this->get(rtrim((string) config('gitchronicle.consulting.support_data.endoflife_url'), '/')."/{$slug}/")->json('result');

            if (! is_array($answer) || ! is_array($answer['releases'] ?? null)) {
                throw new SupportDataException(SupportDataException::UNREADABLE);
            }

            return [
                'fetched_at' => now()->toIso8601String(),
                'label' => (string) ($answer['label'] ?? $slug),
                'url' => (string) ($answer['links']['html'] ?? "https://endoflife.date/{$slug}"),
                'releases' => collect($answer['releases'])
                    ->filter(fn (mixed $release): bool => is_array($release) && is_string($release['name'] ?? null))
                    ->map(fn (array $release): array => [
                        'name' => $release['name'],
                        'label' => (string) ($release['label'] ?? $release['name']),
                        'is_eol' => (bool) ($release['isEol'] ?? false),
                        'eol_from' => is_string($release['eolFrom'] ?? null) ? $release['eolFrom'] : null,
                        'is_eoas' => (bool) ($release['isEoas'] ?? false),
                        'eoas_from' => is_string($release['eoasFrom'] ?? null) ? $release['eoasFrom'] : null,
                        'is_maintained' => (bool) ($release['isMaintained'] ?? false),
                        'is_lts' => (bool) ($release['isLts'] ?? false) && (($release['ltsFrom'] ?? null) === null || Carbon::parse($release['ltsFrom'])->isPast()),
                        'latest' => is_string($release['latest']['name'] ?? null) ? $release['latest']['name'] : null,
                        'latest_date' => is_string($release['latest']['date'] ?? null) ? $release['latest']['date'] : null,
                    ])
                    ->values()
                    ->all(),
            ];
        });
    }

    /**
     * The releases of a runtime with a flag for the security ones: Node.js (the official index) or PHP (php.net).
     * Other runtimes have no such list, so this returns an empty list for them.
     *
     * @return list<array{version: string, security: bool}>
     *
     * @throws SupportDataException
     */
    public function securityFlags(string $product): array
    {
        return match ($product) {
            'nodejs' => $this->cached('releases:nodejs', function (): array {
                $index = $this->get((string) config('gitchronicle.consulting.support_data.node_index_url'))->json();

                if (! is_array($index) || ! array_is_list($index)) {
                    throw new SupportDataException(SupportDataException::UNREADABLE);
                }

                return collect($index)
                    ->filter(fn (mixed $release): bool => is_array($release) && is_string($release['version'] ?? null))
                    ->map(fn (array $release): array => ['version' => ltrim($release['version'], 'v'), 'security' => (bool) ($release['security'] ?? false)])
                    ->values()
                    ->all();
            }),
            'php' => $this->phpReleases(),
            default => [],
        };
    }

    /**
     * php.net answers one request per release line ("8.3"): the lines still maintained are asked, not the old ones.
     *
     * @return list<array{version: string, security: bool}>
     */
    private function phpReleases(): array
    {
        $lines = collect($this->lifecycle('php')['releases'])->where('is_eol', false)->pluck('name');

        return $lines->flatMap(fn (string $line): array => $this->cached("releases:php:{$line}", function () use ($line): array {
            $answer = $this->get((string) config('gitchronicle.consulting.support_data.php_releases_url'), ['json' => 1, 'version' => $line, 'max' => 100])->json();

            if (! is_array($answer)) {
                throw new SupportDataException(SupportDataException::UNREADABLE);
            }

            return collect($answer)
                ->filter(fn (mixed $release, mixed $version): bool => is_array($release) && is_string($version))
                ->map(fn (array $release, string $version): array => ['version' => $version, 'security' => in_array('security', (array) ($release['tags'] ?? []), true)])
                ->values()
                ->all();
        }))->values()->all();
    }

    /**
     * Answer from the cache, or fetch, keep it and answer. A failure is thrown and not kept.
     *
     * @template T of array
     *
     * @param  callable(): T  $fetch
     * @return T
     */
    private function cached(string $key, callable $fetch): array
    {
        $key = "consulting:support:{$key}";
        $kept = Cache::get($key);

        if (is_array($kept)) {
            return $kept;
        }

        $fresh = $fetch();
        Cache::put($key, $fresh, now()->addHours((int) config('gitchronicle.consulting.support_data.cache_hours')));

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws SupportDataException
     */
    private function get(string $url, array $query = []): Response
    {
        try {
            $response = $this->client()->get($url, $query);
        } catch (ConnectionException) {
            throw new SupportDataException(SupportDataException::UNREACHABLE);
        }

        if ($response->failed()) {
            throw new SupportDataException(SupportDataException::HTTP_ERROR, $response->status());
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->acceptJson()->timeout((int) config('gitchronicle.consulting.support_data.timeout'))->retry(2, 300, throw: false);
    }
}
