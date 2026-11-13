{{--
    The frame of Laravel's own error pages (404, 403, 419, 429, 500, 503): every one of them extends
    "errors::minimal", and a file with that name here replaces the framework's white page. It is plain Blade
    (an error can happen before React starts), but it uses the app's stylesheet, so the tokens, the theme and
    the type are the same as on every other page. The titles are translated through lang/ro.json.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <meta name="robots" content="noindex">
    {{-- The same theme choice as app.blade.php, before the first paint (the key is in resources/js/lib/theme.ts). --}}
    <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var theme = null;
            try {
                theme = localStorage.getItem('gitchronicle-theme');
            } catch (e) {}
            document.documentElement.classList.toggle('dark', theme !== 'light');
        })();
    </script>
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="/brand/favicon.ico" sizes="32x32">
    <link rel="icon" href="/brand/gitchronicle-mark.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="bg-canvas font-sans text-ink">
    <main class="relative isolate flex min-h-dvh flex-col items-center justify-center px-4 py-16 text-center">
        <div aria-hidden="true" class="hero-grid pointer-events-none absolute inset-0 -z-10"></div>

        <a href="/" class="inline-flex items-center gap-2 rounded-md text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
            {{-- The Brand 3 mark, the same drawing as resources/js/components/app-logo.tsx. --}}
            <svg aria-hidden="true" viewBox="0 0 32 32" fill="none" class="size-6 shrink-0">
                <path d="M24.36 8.47A11.25 11.25 0 1 0 27.15 17.5H18" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round" />
                <circle cx="24.36" cy="8.47" r="3.6" fill="currentColor" />
                <circle cx="16" cy="17.5" r="3.3" fill="currentColor" />
            </svg>
            <span class="font-brand text-[1.0625rem] leading-none font-semibold tracking-[-0.015em]">{{ config('app.name') }}</span>
        </a>

        <p class="mt-16 font-mono text-sm text-ink-subtle">@yield('code')</p>
        <h1 class="mt-3 max-w-[22ch] font-display text-title font-semibold text-ink">@yield('message')</h1>

        {{-- The primary button of components/button.tsx, written out: Blade cannot import it. --}}
        <a
            href="/"
            class="pressable mt-10 inline-flex h-11 items-center justify-center gap-2 rounded-md bg-accent-strong px-5 text-base leading-none font-semibold whitespace-nowrap text-on-accent shadow-card hover:bg-accent-strong-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
            {{ __('Back to the home page') }}
        </a>
    </main>
</body>
</html>
