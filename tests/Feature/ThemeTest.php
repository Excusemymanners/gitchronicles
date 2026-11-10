<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dark/light theme: the root view (resources/views/app.blade.php) picks the theme in <head>,
 * before the browser paints anything, so the page never flashes white. Dark is the default.
 */
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'home' => ['/'],
            'login' => ['/login'],
            'register' => ['/register'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_page_declares_that_it_supports_both_color_schemes(string $path): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertSee('<meta name="color-scheme" content="dark light">', false);
    }

    #[DataProvider('pages')]
    public function test_the_theme_is_chosen_in_the_head_before_the_page_is_painted_and_defaults_to_dark(string $path): void
    {
        $response = $this->get($path);

        $response->assertOk();
        $response->assertSeeInOrder([
            '<head>',
            "localStorage.getItem('gitchronicle-theme')",
            "document.documentElement.classList.toggle('dark', theme !== 'light')",
            '</head>',
            '<body>',
        ], false);
    }
}
