<?php

namespace Tests\Feature\Auth;

use App\Actions\AddRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_renders_the_register_component_without_a_pending_repository(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('pending_repository', null)
        );
    }

    public function test_registration_page_shows_the_pending_repository_and_keeps_it_in_the_session(): void
    {
        $response = $this->withSession([AddRepository::PENDING_URL_SESSION_KEY => 'https://github.com/laravel/framework'])
            ->get('/register');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('pending_repository', 'https://github.com/laravel/framework')
        );
        $response->assertSessionHas(AddRepository::PENDING_URL_SESSION_KEY, 'https://github.com/laravel/framework');
    }

    public function test_registration_creates_the_user_logs_them_in_and_redirects_home(): void
    {
        $response = $this->post('/register', $this->validRegistration());

        $response->assertRedirect('/');
        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertSame('Ana Popescu', $user->name);
        $this->assertTrue(Hash::check('parola-sigura', $user->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_an_email_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->from('/register')->post('/register', $this->validRegistration());

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rejects_a_password_confirmation_that_does_not_match(): void
    {
        $response = $this->from('/register')->post('/register', [
            ...$this->validRegistration(),
            'password_confirmation' => 'alta-parola',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    public function test_registration_is_limited_to_six_attempts_per_minute(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post('/register', []);
        }

        $response = $this->post('/register', $this->validRegistration());

        $response->assertTooManyRequests();
        $this->assertGuest();
        $this->assertDatabaseEmpty('users');
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function validRegistration(): array
    {
        return [
            'name' => 'Ana Popescu',
            'email' => 'ana@example.com',
            'password' => 'parola-sigura',
            'password_confirmation' => 'parola-sigura',
        ];
    }
}
