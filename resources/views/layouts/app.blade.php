<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite([
            'resources/css/app.css',
            'resources/js/app.js',
            'resources/js/crm/index.js',
        ])
    </head>
    <body class="bg-light">
        <div class="d-flex min-vh-100">
            @include('layouts.sidebar')

            <div class="d-flex flex-column flex-grow-1 w-100">
              
                    @include('layouts.navigation')

                    <!-- Page Heading -->
                    @isset($header)
                        <header class="bg-white shadow-sm border-bottom">
                            <div class="container-fluid py-3 px-4">
                                {{ $header }}
                            </div>
                        </header>
                    @endisset

                    <!-- Page Content -->
                    <main class="flex-grow-1">
                        {{ $slot }}
                    </main>
            </div>
        </div>
        @stack('scripts')
    </body>
</html>
