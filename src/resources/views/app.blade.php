<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Nexus Academic') }}</title>

        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/images/iamgen_sin_fondo.png" />

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Theme Initializer to prevent flash -->
        <script>
            (function() {
                try {
                    const theme = localStorage.getItem('theme') || 'academic';
                    if (theme === 'dark' || theme === 'spatial') {
                        document.documentElement.classList.add('dark');
                        document.documentElement.classList.remove('theme-academic');
                    } else {
                        document.documentElement.classList.add('theme-academic');
                        document.documentElement.classList.remove('dark');
                    }
                } catch (e) {}
            })();
        </script>

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
