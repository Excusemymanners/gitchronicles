<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Document;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The security headers of every response (security audit WEB-LAYER-06, XSS-INJECTION-003, XSS-INJECTION-004).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The public folder is a temporary one, so the test never touches the hot file of a running Vite dev server.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->usePublicPath(storage_path('framework/testing/public'));
        File::ensureDirectoryExists(public_path());
        File::delete(public_path('hot'));
    }

    protected function tearDown(): void
    {
        File::delete(public_path('hot'));

        parent::tearDown();
    }

    public function test_every_page_carries_the_headers_and_a_policy_without_unsafe_scripts(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeaderMissing('X-Powered-By');

        $policy = $response->headers->get('Content-Security-Policy');
        $script = $this->directive($policy, 'script-src');

        $this->assertMatchesRegularExpression("/^script-src 'self' 'nonce-[A-Za-z0-9+\\/=_-]{16,}'$/", $script);
        $this->assertStringNotContainsString('unsafe-inline', $script);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('*', $policy);

        foreach (["object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'", "default-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
    }

    public function test_the_inline_theme_script_carries_the_nonce_of_the_policy(): void
    {
        $response = $this->get(route('home'));

        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $policy);
        preg_match('/<script nonce="([^"]+)">/', $response->getContent(), $page);

        $this->assertNotEmpty($policy[1] ?? null);
        $this->assertSame($policy[1], $page[1] ?? null);
    }

    public function test_every_response_gets_a_new_nonce(): void
    {
        $first = $this->get(route('home'))->headers->get('Content-Security-Policy');
        $second = $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_the_headers_are_also_on_responses_that_are_not_pages(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->create(['slug' => Document::README, 'markdown' => '# Hi']);

        $this->get(route('repositories.docs.readme', $repository))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_a_page_that_does_not_exist_has_the_headers_too(): void
    {
        $this->get('/no/such/page/at/all/here')->assertNotFound()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_the_readme_download_has_nosniff_on_its_own(): void
    {
        $repository = Repository::factory()->ready()->create();
        Document::factory()->for($repository)->create(['slug' => Document::README, 'markdown' => '# Hi']);

        $this->withoutMiddleware(SecurityHeaders::class)
            ->get(route('repositories.docs.readme', $repository))
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_dev_server_is_allowed_only_while_it_runs(): void
    {
        $this->assertStringNotContainsString('http:', $this->get(route('home'))->headers->get('Content-Security-Policy'));

        File::put(public_path('hot'), 'http://[::1]:5173');
        $policy = $this->get(route('home'))->headers->get('Content-Security-Policy');

        $this->assertSame("script-src 'self' 'nonce-".$this->nonceOf($policy)."' http:", $this->directive($policy, 'script-src'));
        $this->assertStringContainsString(' ws:', $this->directive($policy, 'connect-src'));
        $this->assertStringNotContainsString('http:', $this->directive($policy, 'media-src'));
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get(route('home'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get(route('home'), ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    private function nonceOf(string $policy): string
    {
        preg_match("/'nonce-([^']+)'/", $policy, $match);

        return $match[1];
    }

    /**
     * One directive of a policy, e.g. "script-src 'self'".
     */
    private function directive(string $policy, string $name): string
    {
        foreach (explode('; ', $policy) as $directive) {
            if (str_starts_with($directive, $name.' ')) {
                return $directive;
            }
        }

        $this->fail("The policy has no {$name}.");
    }
}
