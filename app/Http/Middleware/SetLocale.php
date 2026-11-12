<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the interface language of every web request, in this order:
 *
 * 1. the visitor's saved choice (the "locale" cookie written by LocaleController);
 * 2. the best match for the browser's Accept-Language header among the supported languages;
 * 3. config('app.fallback_locale'), English.
 *
 * It sets Laravel's locale (__(), validation and auth messages) and Carbon's (translated dates),
 * so everything after it in the request speaks the same language.
 */
class SetLocale
{
    /**
     * The cookie that keeps the visitor's choice. It is not encrypted (bootstrap/app.php): the value is
     * not a secret and it is checked against the supported list on every request, so a changed cookie
     * can only pick another supported language.
     */
    public const COOKIE = 'locale';

    /**
     * Set the language, then handle the request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->localeFor($request);

        App::setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * The supported language codes, e.g. ['en', 'ro'] (config/app.php "supported_locales").
     *
     * @return list<string>
     */
    public static function supportedLocales(): array
    {
        return array_keys(config('app.supported_locales'));
    }

    /**
     * The language of this request: saved choice, then the browser's languages, then the fallback.
     */
    private function localeFor(Request $request): string
    {
        $saved = $request->cookie(self::COOKIE);

        if (is_string($saved) && in_array($saved, self::supportedLocales(), true)) {
            return $saved;
        }

        return $this->browserLocale($request) ?? config('app.fallback_locale');
    }

    /**
     * The first of the browser's languages (already ordered by their q weight) that we support,
     * compared by the language part only: "ro-RO" and "ro" both mean "ro". Null when none is supported.
     */
    private function browserLocale(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            $code = strtolower(explode('_', $language)[0]);

            if (in_array($code, self::supportedLocales(), true)) {
                return $code;
            }
        }

        return null;
    }
}
