<?php

namespace Tests\Feature\Auth;

use App\Actions\AddRepository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertTrue(Hash::check('parola-sigura-2026', $user->password));
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
     * @return array<string, array{string, string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['abc12345', 'password'],
            'common but long enough, no number' => ['password-password', 'password'],
            'no letter' => ['1234567890123', 'password'],
        ];
    }

    #[DataProvider('weakPasswords')]
    public function test_registration_rejects_a_weak_password(string $password, string $field): void
    {
        $response = $this->from('/register')->post('/register', [...$this->validRegistration(), 'password' => $password, 'password_confirmation' => $password]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors($field);
        $this->assertDatabaseEmpty('users');
    }

    public function test_the_message_for_a_missing_number_is_translated(): void
    {
        $this->from('/register')->post('/register', [...$this->validRegistration(), 'password' => 'only-letters-here', 'password_confirmation' => 'only-letters-here'])
            ->assertSessionHasErrors(['password' => __('validation.password.numbers', ['attribute' => __('validation.attributes.password')])]);
    }

    public function test_registration_can_be_closed_by_the_server(): void
    {
        config(['gitchronicle.auth.registration_open' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->validRegistration())->assertNotFound();
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
            'password' => 'parola-sigura-2026',
            'password_confirmation' => 'parola-sigura-2026',
        ];
    }
}
