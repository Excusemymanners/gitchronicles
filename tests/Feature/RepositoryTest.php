<?php

namespace Tests\Feature;

use App\Actions\AddRepository;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The attributes of https://github.com/laravel/framework, the repository used in most tests.
     */
    private const LARAVEL_FRAMEWORK = [
        'url' => 'https://github.com/laravel/framework',
        'owner' => 'laravel',
        'name' => 'framework',
    ];

    public function test_guest_sees_a_repository_page_without_the_user_who_added_it(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $repository = Repository::factory()->cloned()->create([
            ...self::LARAVEL_FRAMEWORK,
            'last_sha' => '0a1b2c3d4e5f60718293a4b5c6d7e8f901234567',
        ]);

        $response = $this->get("/repositories/{$repository->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/show')
            ->where('repository', [
                'id' => $repository->id,
                'url' => 'https://github.com/laravel/framework',
                'owner' => 'laravel',
                'name' => 'framework',
                'full_name' => 'laravel/framework',
                'status' => 'cloned',
                'status_label' => 'Clonat',
                'last_sha' => '0a1b2c3d4e5f60718293a4b5c6d7e8f901234567',
                'created_at' => '2026-11-09T10:00:00+00:00',
            ])
            ->where('can_delete', false)
        );
    }

    public function test_show_offers_delete_to_the_user_who_added_the_repository(): void
    {
        $user = User::factory()->create();
        $repository = Repository::factory()->for($user)->create();

        $response = $this->actingAs($user)->get("/repositories/{$repository->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/show')
            ->where('can_delete', true)
        );
    }

    public function test_show_does_not_offer_delete_to_another_user(): void
    {
        $repository = Repository::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get("/repositories/{$repository->id}");

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('repositories/show')
            ->where('can_delete', false)
        );
    }

    public function test_guest_adding_a_new_repository_is_sent_to_login_and_the_url_waits_in_the_session(): void
    {
        $response = $this->post('/repositories', ['url' => 'github.com/Laravel/Framework.git']);

        $response->assertRedirect('/login');
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/laravel/framework');
        $response->assertInertiaFlash('info', 'Intră în cont ca să adăugăm laravel/framework.');
        $this->assertDatabaseEmpty('repositories');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidUrls(): array
    {
        return [
            'missing' => [null],
            'another host' => ['https://gitlab.com/gitlab-org/gitlab'],
            'owner without name' => ['https://github.com/laravel'],
            'ssh form' => ['git@github.com:laravel/framework.git'],
            'name ..' => ['https://github.com/laravel/..'],
            'not a string' => [['https://github.com/laravel/framework']],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function test_guest_with_an_invalid_url_gets_an_error_and_nothing_waits_in_the_session(mixed $url): void
    {
        $response = $this->from('/')->post('/repositories', ['url' => $url]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('url');
        $response->assertSessionMissing(AddRepository::PENDING_URL_SESSION_KEY);
        $this->assertDatabaseEmpty('repositories');
    }

    public function test_guest_adding_an_existing_repository_is_sent_to_its_page(): void
    {
        $repository = Repository::factory()->create(self::LARAVEL_FRAMEWORK);

        $response = $this->post('/repositories', ['url' => 'https://github.com/laravel/framework']);

        $response->assertRedirect("/repositories/{$repository->id}");
        $response->assertInertiaFlash('info', 'Repository-ul era deja adăugat.');
        $response->assertSessionMissing(AddRepository::PENDING_URL_SESSION_KEY);
        $this->assertDatabaseCount('repositories', 1);
    }

    public function test_user_adding_an_existing_repository_in_another_form_is_sent_to_its_page_without_a_duplicate(): void
    {
        $adder = User::factory()->create();
        $repository = Repository::factory()->for($adder)->create(self::LARAVEL_FRAMEWORK);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/repositories', [
            'url' => 'https://www.GitHub.com/Laravel/Framework/tree/13.x',
        ]);

        $response->assertRedirect("/repositories/{$repository->id}");
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'user_id' => $adder->id]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function acceptedUrlForms(): array
    {
        return [
            'canonical' => ['https://github.com/laravel/framework'],
            'no scheme and .git suffix' => ['github.com/laravel/framework.git'],
            'mixed case with extra path' => ['https://www.GitHub.com/Laravel/Framework/tree/13.x'],
        ];
    }

    #[DataProvider('acceptedUrlForms')]
    public function test_user_adding_a_new_repository_creates_it_as_pending_and_opens_its_page(string $url): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/repositories', ['url' => $url]);

        $repository = Repository::sole();
        $response->assertRedirect("/repositories/{$repository->id}");
        $response->assertInertiaFlash('success', 'Repository adăugat.');
        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'user_id' => $user->id,
            'url' => 'https://github.com/laravel/framework',
            'owner' => 'laravel',
            'name' => 'framework',
            'status' => 'pending',
            'last_sha' => null,
        ]);
    }

    public function test_login_adds_the_repository_the_guest_asked_for_and_opens_its_page(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $repository = Repository::sole();
        $response->assertRedirect("/repositories/{$repository->id}");
        $response->assertInertiaFlash('success', 'Am adăugat laravel/framework.');
        $response->assertSessionMissing(AddRepository::PENDING_URL_SESSION_KEY);
        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'user_id' => $user->id,
            ...self::LARAVEL_FRAMEWORK,
            'status' => 'pending',
        ]);
    }

    public function test_registration_adds_the_repository_the_guest_asked_for_and_opens_its_page(): void
    {
        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->post('/register', [
                'name' => 'Ana Popescu',
                'email' => 'ana@example.com',
                'password' => 'parola-sigura',
                'password_confirmation' => 'parola-sigura',
            ]);

        $user = User::where('email', 'ana@example.com')->sole();
        $repository = Repository::sole();
        $response->assertRedirect("/repositories/{$repository->id}");
        $response->assertInertiaFlash('success', 'Am adăugat laravel/framework.');
        $response->assertSessionMissing(AddRepository::PENDING_URL_SESSION_KEY);
        $this->assertDatabaseHas('repositories', [
            'id' => $repository->id,
            'user_id' => $user->id,
            ...self::LARAVEL_FRAMEWORK,
            'status' => 'pending',
        ]);
    }

    public function test_login_opens_the_pending_repository_without_a_duplicate_when_someone_added_it_meanwhile(): void
    {
        $adder = User::factory()->create();
        $repository = Repository::factory()->for($adder)->create(self::LARAVEL_FRAMEWORK);
        $user = User::factory()->create();

        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertRedirect("/repositories/{$repository->id}");
        $response->assertInertiaFlash('info', 'Repository-ul era deja adăugat.');
        $response->assertSessionMissing(AddRepository::PENDING_URL_SESSION_KEY);
        $this->assertDatabaseCount('repositories', 1);
        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'user_id' => $adder->id]);
    }

    public function test_failed_login_keeps_the_pending_repository_waiting_in_the_session(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $response->assertRedirect('/login');
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/laravel/framework');
        $this->assertGuest();
        $this->assertDatabaseEmpty('repositories');
    }

    public function test_store_allows_each_user_ten_requests_per_minute(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postNewRepositories(10);

        $response = $this->post('/repositories', ['url' => 'https://github.com/laravel/framework']);

        $response->assertTooManyRequests();
        $this->assertDatabaseCount('repositories', 10);
    }

    public function test_store_limit_of_one_user_does_not_block_another_user(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postNewRepositories(10);
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->post('/repositories', ['url' => 'https://github.com/laravel/framework']);

        $response->assertRedirect();
        $this->assertDatabaseHas('repositories', [
            'user_id' => $otherUser->id,
            'url' => 'https://github.com/laravel/framework',
        ]);
    }

    public function test_store_allows_each_guest_ten_requests_per_minute(): void
    {
        $this->postNewRepositories(10);

        $response = $this->post('/repositories', ['url' => 'https://github.com/laravel/framework']);

        $response->assertTooManyRequests();
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/example/repo-10');
    }

    public function test_destroy_deletes_the_repository_of_the_user_who_added_it_and_goes_home(): void
    {
        $user = User::factory()->create();
        $repository = Repository::factory()->for($user)->create();

        $response = $this->actingAs($user)->delete("/repositories/{$repository->id}");

        $response->assertRedirect('/');
        $response->assertInertiaFlash('success', 'Repository șters.');
        $this->assertModelMissing($repository);
    }

    public function test_destroy_forbids_another_user(): void
    {
        $repository = Repository::factory()->create();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete("/repositories/{$repository->id}");

        $response->assertForbidden();
        $this->assertModelExists($repository);
    }

    public function test_destroy_sends_a_guest_to_login_and_keeps_the_repository(): void
    {
        $repository = Repository::factory()->create();

        $response = $this->delete("/repositories/{$repository->id}");

        $response->assertRedirect('/login');
        $this->assertModelExists($repository);
    }

    public function test_repository_stays_public_when_the_user_who_added_it_deletes_their_account(): void
    {
        $adder = User::factory()->create();
        $repository = Repository::factory()->for($adder)->create();

        $adder->delete();

        $this->assertDatabaseHas('repositories', ['id' => $repository->id, 'user_id' => null]);
    }

    public function test_destroy_forbids_everyone_once_the_user_who_added_the_repository_is_gone(): void
    {
        $adder = User::factory()->create();
        $repository = Repository::factory()->for($adder)->create();
        $adder->delete();
        $user = User::factory()->create();

        $response = $this->actingAs($user)->delete("/repositories/{$repository->id}");

        $response->assertForbidden();
        $this->assertModelExists($repository);
    }

    /**
     * Send add requests for new repositories as the current visitor, so the rate limiter counts them.
     */
    private function postNewRepositories(int $count): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $this->post('/repositories', ['url' => "https://github.com/example/repo-{$number}"]);
        }
    }
}
