<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SetLocale runs first, so the shared props, the validation messages and the page all use the chosen language.
        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // A URL that matches no route skips the "web" group, so the 404 page would use the server's default language:
        // SetLocale also runs globally, which makes every error page follow the visitor's language too.
        $middleware->append(SetLocale::class);

        // The language cookie is plain text: it is not a secret and SetLocale checks its value on every request.
        $middleware->encryptCookies(except: [SetLocale::COOKIE]);

        // Guests on "auth" routes go to route('login') (Laravel's default once that route exists).
        // Logged-in users on "guest" routes (/login, /register) go to the home page.
        $middleware->redirectUsersTo(fn () => route('home'));

        // GitHub's webhook cannot send a CSRF token; GitHubWebhookController checks its signature instead.
        $middleware->preventRequestForgery(except: ['webhooks/github']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
