<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'SmartHome') }} — IoT Platform</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
    </head>
    <body class="font-body antialiased bg-iot-bg text-iot-text">

        {{-- Radial gradient background --}}
        <div class="fixed inset-0 pointer-events-none"
             style="background: radial-gradient(ellipse 80% 60% at 50% -10%, rgba(0,229,255,0.08) 0%, transparent 70%);"></div>

        <div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 px-4">

            {{-- Brand mark --}}
            <div class="mb-8 text-center">
                <a href="/" class="inline-flex items-center gap-3 group">
                    <span class="w-2.5 h-2.5 rounded-full bg-iot-accent shadow-[0_0_12px_#00e5ff] animate-pulse"></span>
                    <span class="font-mono text-lg font-bold tracking-widest text-white uppercase group-hover:text-iot-accent transition-colors">
                        SmartHome
                    </span>
                </a>
                <p class="mt-2 font-mono text-xs text-iot-muted tracking-widest uppercase">IoT Platform</p>
            </div>

            {{-- Card --}}
            <div class="w-full sm:max-w-md">
                <div class="bg-iot-surface border border-iot-border rounded-2xl px-8 py-8 shadow-2xl shadow-black/40">
                    {{ $slot }}
                </div>
            </div>

        </div>

        @livewireScripts
    </body>
</html>
