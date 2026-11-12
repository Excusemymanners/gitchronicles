<?php

namespace Tests\Feature\Media;

use App\Media\GitHubMetadata;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What GitHub says about a repository, read from its public API (always Http::fake here).
 */
class GitHubMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reads_the_stars_language_license_and_topics_of_the_repository(): void
    {
        Http::fake(['api.github.com/repos/acme/magazin' => Http::response([
            'stargazers_count' => 1234,
            'forks_count' => 56,
            'language' => 'PHP',
            'license' => ['spdx_id' => 'MIT'],
            'topics' => ['shop', 'laravel'],
            'homepage' => 'https://magazin.example',
            'description' => 'Un magazin mic.',
            'owner' => ['avatar_url' => 'https://avatars.githubusercontent.com/u/1'],
        ])]);
        $repository = Repository::factory()->ready()->create(['owner' => 'acme', 'name' => 'magazin']);

        $facts = app(GitHubMetadata::class)->for($repository);

        $this->assertSame([
            'stars' => 1234,
            'forks' => 56,
            'language' => 'PHP',
            'license' => 'MIT',
            'topics' => ['shop', 'laravel'],
            'homepage' => 'https://magazin.example',
            'description' => 'Un magazin mic.',
            'avatar_url' => 'https://avatars.githubusercontent.com/u/1',
        ], $facts);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/repos/acme/magazin'
            && $request->hasHeader('User-Agent', 'GitChronicle')
            && ! $request->hasHeader('Authorization'));
    }

    public function test_an_answer_is_reused_from_the_cache(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 7])]);
        $repository = Repository::factory()->ready()->create();

        app(GitHubMetadata::class)->for($repository);
        $facts = app(GitHubMetadata::class)->for($repository);

        $this->assertSame(7, $facts['stars']);
        Http::assertSentCount(1);
    }

    public function test_empty_fields_and_an_unrecognized_license_are_unknown(): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            'stargazers_count' => 0,
            'language' => null,
            'license' => ['spdx_id' => 'NOASSERTION'],
            'homepage' => '',
            'description' => '  ',
        ])]);

        $facts = app(GitHubMetadata::class)->for(Repository::factory()->ready()->create());

        $this->assertSame(['stars' => 0, 'forks' => 0, 'language' => null, 'license' => null, 'topics' => [], 'homepage' => null, 'description' => null, 'avatar_url' => null], $facts);
    }

    public function test_a_failure_gives_null_and_is_tried_again_next_time(): void
    {
        Http::fakeSequence('api.github.com/*')
            ->push(['message' => 'API rate limit exceeded'], 403)
            ->push(['stargazers_count' => 3]);
        $repository = Repository::factory()->ready()->create();

        $this->assertNull(app(GitHubMetadata::class)->for($repository));
        $this->assertSame(3, app(GitHubMetadata::class)->for($repository)['stars']);
    }

    public function test_no_network_gives_null(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->assertNull(app(GitHubMetadata::class)->for(Repository::factory()->ready()->create()));
    }
}
