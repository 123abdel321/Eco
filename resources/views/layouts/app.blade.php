<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name', 'Laravel'))</title>
        <meta name="description" content="@yield('description', 'Aplicación Laravel')">

        <!-- Preconnect para optimización -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="dns-prefetch" href="https://fonts.bunny.net">

        <!-- Fonts -->
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Favicon -->
        <link rel="icon" type="image/x-icon" href="/favicon.ico">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <!-- Scripts adicionales por página -->
        @stack('scripts')
    </head>
    <body class="font-sans antialiased h-full">
        <div class="min-h-screen bg-gray-100 dark:bg-gray-900 flex flex-col">
            @include('layouts.navigation')

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white dark:bg-gray-800 shadow-sm sticky top-0 z-10">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                {{ $header }}
                            </div>
                            @hasSection('header-actions')
                                <div class="flex items-center space-x-3">
                                    @yield('header-actions')
                                </div>
                            @endif
                        </div>
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main class="flex-1">
                <!-- Contenedor para mensajes flash -->
                @if(session('success') || session('error') || session('warning'))
                    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                        @if(session('success'))
                            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                                <span class="block sm:inline">{{ session('success') }}</span>
                            </div>
                        @endif
                        
                        @if(session('error'))
                            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                                <span class="block sm:inline">{{ session('error') }}</span>
                            </div>
                        @endif
                        
                        @if(session('warning'))
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
                                <span class="block sm:inline">{{ session('warning') }}</span>
                            </div>
                        @endif
                    </div>
                @endif

                {{ $slot }}
            </main>

            <!-- Footer opcional -->
            @hasSection('footer')
                <footer class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        @yield('footer')
                    </div>
                </footer>
            @else
                <footer class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 mt-auto">
                    <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                        <p class="text-center text-gray-500 dark:text-gray-400 text-sm">
                            &copy; {{ date('Y') }} {{ config('app.name', 'Laravel') }}. Todos los derechos reservados.
                        </p>
                    </div>
                </footer>
            @endif
        </div>

        <!-- Script para modo oscuro persistente -->
        <script>
            // Verificar preferencia de tema al cargar
            (function() {
                const storedTheme = localStorage.getItem('theme');
                const systemPrefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                
                if (storedTheme === 'dark' || (!storedTheme && systemPrefersDark)) {
                    document.documentElement.classList.add('dark');
                } else {
                    document.documentElement.classList.remove('dark');
                }
            })();
        </script>
    </body>
</html>