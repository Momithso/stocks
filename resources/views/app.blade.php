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
