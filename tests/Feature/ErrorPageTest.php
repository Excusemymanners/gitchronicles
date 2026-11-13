<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laravel's own error pages (404, 500, ...) use resources/views/errors/minimal.blade.php: the app's look, the logo,
 * the error code and title, and the way back to the home page.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_page_shows_the_branded_error_page(): void
    {
        $this->get('/repositories/999999')
            ->assertNotFound()
            ->assertSee('404')
            ->assertSee('Not Found')
            ->assertSee('Back to the home page')
            ->assertSee('href="/"', false);
    }
}
