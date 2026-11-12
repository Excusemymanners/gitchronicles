<?php

namespace Tests\Feature;

use App\Http\Controllers\FrontDoorController;
use App\Jobs\ProcessRepository;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The front door: {host}/owner/name opens the repository's page when we document it, and otherwise the
 * „Document this repository” page built from GitHub's real facts. GitHub is always faked.
 */
class FrontDoorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A repository answer shaped like GET /repos/{owner}/{name}.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function githubRepository(array $overrides = []): array
    {
        return array_replace([
            'full_name' => 'octocat/Spoon-Knife',
            'private' => false,
            'description' => 'This repo is for demonstration purposes only.',
            'owner' => ['avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4'],
            'stargazers_count' => 13000,
            'forks_count' => 144000,
            'language' => 'HTML',
            'license' => ['spdx_id' => 'MIT'],
            'pushed_at' => '2024-03-04T10:00:00Z',
            'default_branch' => 'main',
        ], $overrides);
    }

    private function fakeGitHub(int $status = 200, array $body = [], array $headers = []): void
    {
        Http::fake(['api.github.com/repos/*' => Http::response($status === 200 && $body === [] ? $this->githubRepository() : $body, $status, $headers)]);
    }

    public function test_every_top_level_route_is_reserved(): void
    {
        $unreserved = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => $route->getName() === 'front-door' || str_starts_with($route->uri(), 'github.com/'))
            ->map(fn ($route) => explode('/', $route->uri())[0])
            ->reject(fn (string $segment) => $segment === '' || str_starts_with($segment, '{'))
            ->unique()
            ->reject(fn (string $segment) => in_array($segment, FrontDoorController::RESERVED_SEGMENTS, true));

        $this->assertSame(
            [],
            $unreserved->values()->all(),
            'Add the new top-level path(s) to FrontDoorController::RESERVED_SEGMENTS, or the front door route would shadow them.',
        );
    }

    #[DataProvider('reservedPaths')]
    public function test_reserved_first_segments_never_reach_the_front_door(string $path): void
    {
        Http::fake();

        $this->get($path)->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedPaths(): array
    {
        return [
            'login' => ['/login/foo'],
            'discover' => ['/discover/foo'],
            'repositories' => ['/repositories/foo/bar'],
            'webhooks' => ['/webhooks/foo'],
            'build' => ['/build/foo'],
            'up' => ['/up/foo'],
            'locale' => ['/locale/foo'],
            'register' => ['/register/foo'],
        ];
    }

    #[DataProvider('invalidAddresses')]
    public function test_addresses_that_break_github_rules_are_not_found(string $path): void
    {
        Http::fake();

        $this->get($path)->assertNotFound();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAddresses(): array
    {
        return [
            'owner starts with a hyphen' => ['/-octocat/Spoon-Knife'],
            'owner ends with a hyphen' => ['/octocat-/Spoon-Knife'],
            'owner with two hyphens in a row' => ['/octo--cat/Spoon-Knife'],
            'owner with an underscore' => ['/octo_cat/Spoon-Knife'],
            'owner longer than 39' => ['/'.str_repeat('a', 40).'/Spoon-Knife'],
            'name with a space' => ['/octocat/Spoon%20Knife'],
            'name of only dots' => ['/octocat/..'],
            'name longer than 100' => ['/octocat/'.str_repeat('a', 106)],
            'only a suffix' => ['/octocat/.git'],
            'a single segment' => ['/octocat'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedForms(): array
    {
        return [
            'plain' => ['/octocat/Spoon-Knife'],
            'lowercase' => ['/octocat/spoon-knife'],
            'with .git' => ['/octocat/Spoon-Knife.git'],
            'with a trailing slash' => ['/octocat/Spoon-Knife/'],
            'with a branch and a folder' => ['/octocat/Spoon-Knife/tree/main/src'],
            'with a file' => ['/octocat/Spoon-Knife/blob/main/README.md'],
            'with the host pasted' => ['/github.com/octocat/Spoon-Knife'],
            'with the host and .git' => ['/github.com/octocat/Spoon-Knife.git'],
            'with the host and a path' => ['/github.com/octocat/Spoon-Knife/issues/12'],
        ];
    }

    #[DataProvider('acceptedForms')]
    public function test_every_accepted_form_shows_the_same_repository(string $path): void
    {
        $this->fakeGitHub();

        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('front-door/document')
                ->where('full_name', 'octocat/Spoon-Knife')
                ->where('url', 'https://github.com/octocat/spoon-knife'));
    }

    #[DataProvider('acceptedForms')]
    public function test_a_known_repository_redirects_to_its_page_in_every_form(string $path): void
    {
        Http::fake();
        $repository = Repository::factory()->create([
            'url' => 'https://github.com/octocat/spoon-knife',
            'owner' => 'octocat',
            'name' => 'spoon-knife',
        ]);

        $this->get($path)->assertRedirect(route('repositories.show', $repository));

        Http::assertNothingSent();
    }

    public function test_the_known_repository_match_ignores_case(): void
    {
        $repository = Repository::factory()->create([
            'url' => 'https://github.com/laravel/framework',
            'owner' => 'laravel',
            'name' => 'framework',
        ]);

        $this->get('/LARAVEL/Framework')->assertStatus(302)->assertRedirect(route('repositories.show', $repository));
    }

    public function test_an_unknown_repository_shows_its_real_github_facts(): void
    {
        $this->fakeGitHub();

        $response = $this->get('/octocat/Spoon-Knife');

        $response->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertInertia(fn (Assert $page) => $page
                ->component('front-door/document')
                ->where('facts', [
                    'full_name' => 'octocat/Spoon-Knife',
                    'description' => 'This repo is for demonstration purposes only.',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/583231?v=4',
                    'stars' => 13000,
                    'forks' => 144000,
                    'language' => 'HTML',
                    'license' => 'MIT',
                    'pushed_at' => '2024-03-04T10:00:00Z',
                    'default_branch' => 'main',
                ]));
    }

    public function test_a_license_github_cannot_identify_is_left_out(): void
    {
        $this->fakeGitHub(body: $this->githubRepository(['license' => ['spdx_id' => 'NOASSERTION'], 'description' => null]));

        $this->get('/octocat/Spoon-Knife')->assertInertia(fn (Assert $page) => $page
            ->where('facts.license', null)
            ->where('facts.description', null));
    }

    public function test_a_renamed_repository_is_added_under_the_name_github_gives(): void
    {
        $this->fakeGitHub(body: $this->githubRepository(['full_name' => 'octocat/New-Name']));

        $this->get('/octocat/Old-Name')->assertInertia(fn (Assert $page) => $page
            ->where('full_name', 'octocat/New-Name')
            ->where('url', 'https://github.com/octocat/new-name'));
    }

    public function test_a_missing_repository_is_a_404_page(): void
    {
        $this->fakeGitHub(404, ['message' => 'Not Found']);

        $this->get('/octocat/does-not-exist')
            ->assertNotFound()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertInertia(fn (Assert $page) => $page
                ->component('front-door/not-found')
                ->where('full_name', 'octocat/does-not-exist'));
    }

    public function test_a_private_repository_is_shown_as_missing_even_when_a_token_can_see_it(): void
    {
        $this->fakeGitHub(body: $this->githubRepository(['private' => true]));

        $this->get('/octocat/secret')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('front-door/not-found'));
    }

    public function test_when_github_is_down_the_page_appears_without_facts(): void
    {
        Sleep::fake();
        $this->fakeGitHub(503, ['message' => 'Service Unavailable']);

        $this->get('/octocat/Spoon-Knife')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('front-door/document')
                ->where('facts', null)
                ->where('full_name', 'octocat/spoon-knife')
                ->where('url', 'https://github.com/octocat/spoon-knife'));
    }

    public function test_when_github_rate_limits_the_page_appears_without_facts(): void
    {
        $this->fakeGitHub(403, ['message' => 'API rate limit exceeded'], ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) (time() + 3000)]);

        $this->get('/octocat/Spoon-Knife')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('front-door/document')->where('facts', null));
    }

    public function test_the_answer_is_cached_so_two_visits_cost_one_api_call(): void
    {
        $this->fakeGitHub();

        $this->get('/octocat/Spoon-Knife')->assertOk();
        $this->get('/OCTOCAT/spoon-knife.git')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_missing_repository_is_cached_too(): void
    {
        $this->fakeGitHub(404, ['message' => 'Not Found']);

        $this->get('/octocat/nope')->assertNotFound();
        $this->get('/octocat/nope')->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_the_cached_answer_expires_after_ten_minutes(): void
    {
        $this->fakeGitHub();

        $this->get('/octocat/Spoon-Knife');
        $this->travel(11)->minutes();
        $this->get('/octocat/Spoon-Knife');

        Http::assertSentCount(2);
    }

    public function test_only_a_generic_user_agent_is_sent_to_github(): void
    {
        $this->fakeGitHub();

        $this->get('/octocat/Spoon-Knife');

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://api.github.com/repos/octocat/spoon-knife'
                && $request->header('User-Agent') === ['GitChronicle']
                && ! $request->hasHeader('Referer')
                && ! $request->hasHeader('Cookie');
        });
    }

    public function test_the_route_is_throttled_per_ip(): void
    {
        Repository::factory()->create(['url' => 'https://github.com/octocat/spoon-knife', 'owner' => 'octocat', 'name' => 'spoon-knife']);

        foreach (range(1, 30) as $ignored) {
            $this->get('/octocat/Spoon-Knife')->assertRedirect();
        }

        $this->get('/octocat/Spoon-Knife')->assertStatus(429);
    }

    public function test_a_guest_who_uses_the_action_logs_in_and_the_repository_is_added(): void
    {
        Bus::fake();
        $this->fakeGitHub();
        $user = User::factory()->create(['password' => 'secret-password']);

        $url = 'https://github.com/octocat/spoon-knife';

        $this->post('/repositories', ['url' => $url])->assertRedirect(route('login'));
        $this->assertDatabaseCount('repositories', 0);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);

        $this->assertDatabaseHas('repositories', ['url' => 'https://github.com/octocat/spoon-knife', 'user_id' => $user->id]);
        Bus::assertDispatched(ProcessRepository::class);
    }

    public function test_a_logged_in_user_who_uses_the_action_gets_the_repository_added(): void
    {
        Bus::fake();
        $this->fakeGitHub();
        $user = User::factory()->create();

        $url = 'https://github.com/octocat/spoon-knife';

        $this->actingAs($user)->post('/repositories', ['url' => $url]);

        $repository = Repository::firstWhere('url', $url);
        $this->assertNotNull($repository);
        $this->assertTrue($repository->user->is($user));
        Bus::assertDispatched(ProcessRepository::class);
    }

    public function test_existing_routes_still_resolve(): void
    {
        $repository = Repository::factory()->create();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('home'));
        $this->get('/discover')->assertOk()->assertInertia(fn (Assert $page) => $page->component('discover'));
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/login'));
        $this->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/register'));
        $this->get("/repositories/{$repository->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('repositories/show'));
        $this->get("/repositories/{$repository->id}/timeline")->assertOk();
    }

    public function test_no_get_creates_a_repository(): void
    {
        $this->fakeGitHub();
        $user = User::factory()->create();

        foreach (['/octocat/Spoon-Knife', '/github.com/octocat/Spoon-Knife.git', '/octocat/Spoon-Knife/tree/main'] as $path) {
            $this->get($path);
            $this->actingAs($user)->get($path);
        }

        $this->assertDatabaseCount('repositories', 0);
    }

    public function test_the_home_page_carries_the_host_of_the_app_url(): void
    {
        config(['app.url' => 'https://chronicle.example.com']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('app_host', 'chronicle.example.com'));
    }
}
