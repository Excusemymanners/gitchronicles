<?php

namespace Tests\Unit;

use App\Support\GitHubUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubUrlTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedUrls(): array
    {
        $canonical = 'https://github.com/laravel/framework';

        return [
            'https' => ['https://github.com/laravel/framework', $canonical],
            'http' => ['http://github.com/laravel/framework', $canonical],
            'no scheme' => ['github.com/laravel/framework', $canonical],
            'www' => ['https://www.github.com/laravel/framework', $canonical],
            'trailing slash' => ['https://github.com/laravel/framework/', $canonical],
            '.git suffix' => ['https://github.com/laravel/framework.git', $canonical],
            'extra path' => ['https://github.com/laravel/framework/tree/main/src', $canonical],
            'query string' => ['https://github.com/laravel/framework?tab=readme-ov-file', $canonical],
            'hash' => ['https://github.com/laravel/framework#readme', $canonical],
            'mixed case' => ['HTTPS://WWW.GitHub.com/Laravel/Framework.GIT', $canonical],
            'surrounding spaces' => ['  https://github.com/laravel/framework  ', $canonical],
            'dot inside the name' => ['https://github.com/vercel/next.js', 'https://github.com/vercel/next.js'],
            'name starting with a dot' => ['https://github.com/octo-org/.github', 'https://github.com/octo-org/.github'],
            'owner of 39 characters' => [
                'https://github.com/'.str_repeat('a', 39).'/repo',
                'https://github.com/'.str_repeat('a', 39).'/repo',
            ],
            'name of 100 characters' => [
                'https://github.com/laravel/'.str_repeat('a', 100),
                'https://github.com/laravel/'.str_repeat('a', 100),
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            'another host' => ['https://gitlab.com/gitlab-org/gitlab'],
            'host that only starts with github.com' => ['https://github.com.example.org/laravel/framework'],
            'owner without name' => ['https://github.com/laravel'],
            'owner with trailing slash' => ['https://github.com/laravel/'],
            'ssh form' => ['git@github.com:laravel/framework.git'],
            'not a url' => ['not a url'],
            'owner starting with a hyphen' => ['https://github.com/-laravel/framework'],
            'owner ending with a hyphen' => ['https://github.com/laravel-/framework'],
            'owner with two hyphens in a row' => ['https://github.com/lara--vel/framework'],
            'owner of 40 characters' => ['https://github.com/'.str_repeat('a', 40).'/repo'],
            'name ..' => ['https://github.com/laravel/..'],
            'name .' => ['https://github.com/laravel/.'],
            'name of 101 characters' => ['https://github.com/laravel/'.str_repeat('a', 101)],
            'space inside the name' => ['https://github.com/laravel/frame work'],
            'empty' => [''],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function test_normalizes_accepted_forms_to_the_canonical_url(string $input, string $expected): void
    {
        $this->assertSame($expected, GitHubUrl::normalize($input));
    }

    #[DataProvider('rejectedUrls')]
    public function test_returns_rejected_input_trimmed_and_otherwise_unchanged(string $input): void
    {
        $normalized = GitHubUrl::normalize(" {$input} ");

        $this->assertSame($input, $normalized);
        $this->assertNull(GitHubUrl::parse($normalized));
    }

    public function test_parse_splits_a_canonical_url_into_owner_and_name(): void
    {
        $parts = GitHubUrl::parse('https://github.com/laravel/framework');

        $this->assertSame(['owner' => 'laravel', 'name' => 'framework'], $parts);
    }

    #[DataProvider('nonCanonicalUrls')]
    public function test_parse_returns_null_for_a_url_that_is_not_canonical(string $url): void
    {
        $this->assertNull(GitHubUrl::parse($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonCanonicalUrls(): array
    {
        return [
            'uppercase letters' => ['https://github.com/Laravel/framework'],
            'no scheme' => ['github.com/laravel/framework'],
            'http' => ['http://github.com/laravel/framework'],
            'trailing slash' => ['https://github.com/laravel/framework/'],
            'trailing newline' => ["https://github.com/laravel/framework\n"],
        ];
    }
}
