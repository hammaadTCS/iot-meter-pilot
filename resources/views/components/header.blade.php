@props(['breadcrumbs' => [], 'actions' => null])

<header class="flex-shrink-0 flex items-center justify-between h-16 px-4 sm:px-6
               bg-iot-surface border-b border-iot-border z-10">

    <div class="flex items-center gap-4 min-w-0">
        {{-- Mobile hamburger --}}
        <button @click="sidebarOpen = !sidebarOpen"
                class="lg:hidden p-2 rounded-lg text-iot-muted hover:text-white hover:bg-iot-surface2 transition-colors flex-shrink-0"
                aria-label="Toggle sidebar">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
        </button>

        {{-- Breadcrumbs --}}
        @if(count($breadcrumbs))
        <nav class="flex items-center gap-1.5 text-sm min-w-0 overflow-hidden">
            @foreach($breadcrumbs as $crumb)
                @if(!$loop->last)
                    <a href="{{ $crumb['href'] }}"
                       class="text-iot-muted hover:text-white transition-colors whitespace-nowrap flex-shrink-0">
                        {{ $crumb['label'] }}
                    </a>
                    <svg class="w-3.5 h-3.5 text-iot-border flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                @else
                    <span class="text-white font-medium truncate">{{ $crumb['label'] }}</span>
                @endif
            @endforeach
        </nav>
        @endif
    </div>

    <div class="flex items-center gap-3 flex-shrink-0">
        {{-- Page actions slot --}}
        @if($actions)
            {{ $actions }}
        @endif

        {{-- User dropdown --}}
        <x-dropdown align="right" width="48">
            <x-slot name="trigger">
                <button class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg
                               hover:bg-iot-surface2 transition-colors text-sm text-iot-muted hover:text-white">
                    <div class="w-7 h-7 rounded-full bg-iot-accent/20 border border-iot-accent/30
                                flex items-center justify-center">
                        <span class="font-mono text-xs font-bold text-iot-accent">
                            {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                        </span>
                    </div>
                    <span class="hidden sm:block font-medium text-iot-text">{{ auth()->user()->name }}</span>
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </x-slot>
            <x-slot name="content">
                <div class="px-4 py-3 border-b border-iot-border">
                    <p class="text-sm font-medium text-white">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-iot-muted mt-0.5">{{ auth()->user()->email }}</p>
                </div>
                <x-dropdown-link :href="route('profile.edit')">
                    <svg class="w-4 h-4 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    Profile
                </x-dropdown-link>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-dropdown-link :href="route('logout')"
                        onclick="event.preventDefault(); this.closest('form').submit();">
                        <svg class="w-4 h-4 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Sign Out
                    </x-dropdown-link>
                </form>
            </x-slot>
        </x-dropdown>
    </div>
</header>
