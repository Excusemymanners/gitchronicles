<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * The language switch: saves the chosen language and goes back to the page it was chosen on,
 * which then comes back in that language. It works the same for guests and logged-in users,
 * because the choice lives in a cookie, not on the account.
 */
class LocaleController extends Controller
{
    /**
     * Save the choice in a long-lived cookie (Cookie::forever, five years); SetLocale reads it
     * on every following request.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::supportedLocales())],
        ]);

        return back(fallback: route('home'))
            ->withCookie(Cookie::forever(SetLocale::COOKIE, $validated['locale']));
    }
}
