<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Pagina are ambele teme; browserul își alege de aici culorile proprii (bare de derulare, câmpuri). --}}
    <meta name="color-scheme" content="dark light">
    {{--
        Tema se alege aici, înainte ca browserul să deseneze ceva, ca pagina să nu clipească în alb:
        întunecată, dacă utilizatorul nu a ales „luminoasă” (cheia e aceeași în resources/js/lib/theme.ts).
    --}}
    <script>
        (function () {
            var theme = null;
            try {
                theme = localStorage.getItem('gitchronicle-theme');
            } catch (e) {}
            document.documentElement.classList.toggle('dark', theme !== 'light');
        })();
    </script>
    <title inertia>{{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @fonts
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
