<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The interface language: SetLocale picks it for every request (saved cookie, then the browser's
 * Accept-Language, then English), LocaleController saves the visitor's choice, and the page gets it
 * as the shared "locale" prop and as <html lang>. Server messages follow it.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_english_is_the_default_without_a_saved_choice_or_browser_languages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<html lang="en">', false)
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function browserLanguages(): array
    {
        return [
            'Romanian with a region' => ['ro-RO,ro;q=0.9,en-US;q=0.8,en;q=0.7', 'ro'],
            'English first' => ['en-GB,en;q=0.9,ro;q=0.8', 'en'],
            'an unsupported language first, then Romanian' => ['de-DE,de;q=0.9,ro;q=0.5', 'ro'],
            'the weights decide, not the order' => ['en;q=0.4,ro;q=0.9', 'ro'],
            'only unsupported languages' => ['fr-FR,fr;q=0.9,de;q=0.8', 'en'],
        ];
    }

    #[DataProvider('browserLanguages')]
    public function test_without_a_saved_choice_the_best_supported_browser_language_is_used(string $acceptLanguage, string $expected): void
    {
        $this->withHeader('Accept-Language', $acceptLanguage)
            ->get('/')
            ->assertSee('<html lang="'.$expected.'">', false)
            ->assertInertia(fn (Assert $page) => $page->where('locale', $expected));
    }

    public function test_the_saved_choice_wins_over_the_browser_languages(): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'ro')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/login')
            ->assertSee('<html lang="ro">', false)
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ro'));

        $this->assertSame('ro', Carbon::getLocale());
    }

    public function test_an_unsupported_saved_value_is_ignored(): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'xx')
            ->withHeader('Accept-Language', 'ro')
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'ro'));

        $this->withUnencryptedCookie(SetLocale::COOKIE, '../en')
            ->withHeader('Accept-Language', '')
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    public function test_every_page_gets_the_current_locale_and_the_languages_of_the_switch(): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'ro')
            ->get('/discover')
            ->assertInertia(fn (Assert $page) => $page
                ->where('locale', 'ro')
                ->where('locales', ['en' => 'English', 'ro' => 'Română'])
            );
    }

    public function test_a_guest_saves_the_choice_in_a_long_lived_plain_cookie_and_goes_back(): void
    {
        $response = $this->from('/discover')->post(route('locale.update'), ['locale' => 'ro']);

        $response->assertRedirect('/discover');
        $response->assertPlainCookie(SetLocale::COOKIE, 'ro');

        $cookie = collect($response->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === SetLocale::COOKIE);
        $this->assertGreaterThan(now()->addYear()->getTimestamp(), $cookie->getExpiresTime());
    }

    public function test_a_logged_in_user_saves_the_choice_the_same_way(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->from('/')
            ->post(route('locale.update'), ['locale' => 'en']);

        $response->assertRedirect('/');
        $response->assertPlainCookie(SetLocale::COOKIE, 'en');
    }

    public function test_an_unsupported_choice_is_refused_and_nothing_is_saved(): void
    {
        $response = $this->from('/')->post(route('locale.update'), ['locale' => 'fr']);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('locale');
        $response->assertCookieMissing(SetLocale::COOKIE);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function flashMessages(): array
    {
        return [
            'English' => ['en', 'Log in and we will add laravel/framework.'],
            'Romanian' => ['ro', 'Intră în cont ca să adăugăm laravel/framework.'],
        ];
    }

    #[DataProvider('flashMessages')]
    public function test_flash_messages_are_in_the_chosen_language(string $locale, string $expected): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, $locale)
            ->post('/repositories', ['url' => 'https://github.com/laravel/framework'])
            ->assertInertiaFlash('info', $expected);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function validationMessages(): array
    {
        return [
            'English' => ['en', 'The link field is required.', 'Enter a GitHub link like https://github.com/owner/name.'],
            'Romanian' => ['ro', 'Câmpul link este obligatoriu.', 'Introdu un link de GitHub de forma https://github.com/proprietar/nume.'],
        ];
    }

    #[DataProvider('validationMessages')]
    public function test_validation_messages_are_in_the_chosen_language(string $locale, string $required, string $invalidLink): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, $locale)
            ->post('/repositories', ['url' => ''])
            ->assertSessionHasErrors(['url' => $required]);

        $this->withUnencryptedCookie(SetLocale::COOKIE, $locale)
            ->post('/repositories', ['url' => 'https://gitlab.com/owner/name'])
            ->assertSessionHasErrors(['url' => $invalidLink]);
    }

    public function test_a_failed_login_is_explained_in_the_chosen_language(): void
    {
        $user = User::factory()->create();

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'ro')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'Emailul sau parola nu sunt corecte.']);

        $this->withUnencryptedCookie(SetLocale::COOKIE, 'en')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
    }

    public function test_the_not_found_page_follows_the_visitors_language(): void
    {
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'ro')->get('/no-such-page-xyz')->assertNotFound()->assertSee('Pagina nu există');
        $this->withUnencryptedCookie(SetLocale::COOKIE, 'en')->get('/no-such-page-xyz')->assertNotFound()->assertSee('Not Found');
    }
}
