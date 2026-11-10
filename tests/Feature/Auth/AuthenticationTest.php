<?php

namespace Tests\Feature\Auth;

use App\Actions\AddRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_the_login_component_without_a_pending_repository(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('pending_repository', null)
        );
    }

    public function test_login_page_shows_the_pending_repository_and_keeps_it_in_the_session(): void
    {
        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->get('/login');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('pending_repository', 'https://github.com/laravel/framework')
        );
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/laravel/framework');
    }

    public function test_user_logs_in_and_is_redirected_home(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_a_wrong_password_fails_on_the_email_field(): void
    {
        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts_even_with_the_right_password(): void
    {
        $user = User::factory()->create();
        $this->failLogins($user, 5);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_successful_login_resets_the_failed_attempts(): void
    {
        $user = User::factory()->create();
        $this->failLogins($user, 4);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');
        $this->failLogins($user, 4);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * No page needs an account yet, so the test saves the page in the session
     * the way the "auth" middleware does before it sends a guest to log in.
     */
    public function test_login_returns_the_user_to_the_page_saved_before_the_login(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['url.intended' => url('/saved-page')])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/saved-page');
    }

    public function test_user_logs_out_and_is_redirected_home(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function guestOnlyPages(): array
    {
        return [
            'login' => ['/login'],
            'register' => ['/register'],
        ];
    }

    #[DataProvider('guestOnlyPages')]
    public function test_logged_in_user_is_redirected_from_guest_pages_to_home(string $uri): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get($uri);

        $response->assertRedirect('/');
    }

    /**
     * Send login requests with a wrong password.
     */
    private function failLogins(User $user, int $times): void
    {
        for ($attempt = 1; $attempt <= $times; $attempt++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }
    }
}
