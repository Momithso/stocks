@php
    // accent colour of the settings as rgb channels, the hover colour is mixed with 20 % white
    $accent = sscanf(app(\App\Support\Settings::class)->get('organization.accent_color'), '#%02x%02x%02x') ?: [34, 148, 140];
    $accentHover = array_map(fn (int $channel) => (int) round($channel + (255 - $channel) * 0.2), $accent);
@endphp
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="icon" href="{{ app(\App\Support\Branding::class)->url('favicon') }}">

    {{-- web app: added to the home screen it runs in full screen (App\Http\Controllers\WebAppController) --}}
    {{-- a surface (kiosk) brings its own web app, which starts on its own page --}}
    <link rel="manifest" href="{{ \App\Support\Surface::current()['manifest'] ?? '/manifest.webmanifest' }}">
    <link rel="apple-touch-icon" href="/web-app/icon-180.png?v={{ pathinfo((string) app(\App\Support\Branding::class)->path('logo'), PATHINFO_FILENAME) ?: 'default' }}">
    <meta name="theme-color" content="{{ app(\App\Support\Settings::class)->get('organization.accent_color') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">

    <title inertia>{{ app(\App\Support\Settings::class)->get('organization.name') }} · Stocks</title>

    <style>
        :root {
            --color-accent: {{ implode(' ', $accent) }};
            --color-accent-hover: {{ implode(' ', $accentHover) }};
        }
    </style>

    {{-- set the colour mode before the first paint, see resources/js/composables/useColorMode.ts --}}
    <script>
        try {
            if (localStorage.theme === 'dark' || (!('theme' in localStorage) && matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    </script>

    @inertiaHead
    @vite(['resources/js/app.ts'])
</head>

<body>
    @inertia
</body>

</html>
