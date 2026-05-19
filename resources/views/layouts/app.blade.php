<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.name', 'SmartHome') }} — IoT Platform</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">

        @stack('styles')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-body antialiased bg-iot-bg text-iot-text">

        <div x-data="{ sidebarOpen: false }" class="flex h-screen overflow-hidden">

            {{-- Sidebar --}}
            <x-sidebar />

            {{-- Mobile backdrop --}}
            <div
                x-show="sidebarOpen"
                x-transition:enter="transition-opacity ease-linear duration-200"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-linear duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                @click="sidebarOpen = false"
                class="fixed inset-0 z-20 bg-black/50 lg:hidden"
                style="display: none;"
            ></div>

            {{-- Main area --}}
            <div class="flex flex-col flex-1 min-w-0 overflow-hidden">

                <x-header />

                <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                    {{ $slot }}
                </main>

            </div>
        </div>

        {{-- Global toast notification --}}
        <x-toast />

        @stack('scripts')
        @livewireScripts
    </body>
</html>
