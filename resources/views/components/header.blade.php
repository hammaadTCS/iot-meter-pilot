@props(['breadcrumbs' => [], 'actions' => null])

@php
    // Real notifications for the signed-in user (database channel). The bell
    // shows the 10 most recent; the ping dot + "N new" badge use the unread count.
    $authUser = auth()->user();
    $notifications = $authUser
        ? $authUser->notifications()->latest()->limit(10)->get()
        : collect();
    $unreadCount = $authUser ? $authUser->unreadNotifications()->count() : 0;

    // Per-severity icon accent, aligned with the iot-* token palette.
    $severityAccent = [
        'critical' => 'text-iot-red',
        'warning'  => 'text-iot-amber',
        'info'     => 'text-iot-accent',
    ];
@endphp

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

        {{-- Notifications (FRONT-END ONLY — no backend logic yet).
             Reuses the shared <x-dropdown> (Alpine-driven open/close + click-outside)
             with a wider panel and custom content classes so the list clips cleanly
             to the rounded corners. --}}
        <x-dropdown align="right" width="w-80"
            contentClasses="bg-iot-surface border border-iot-border rounded-xl shadow-2xl shadow-black/50 overflow-hidden">
            <x-slot name="trigger">
                <button type="button"
                        class="relative flex items-center justify-center w-9 h-9 rounded-lg
                               text-iot-muted hover:text-white hover:bg-iot-surface2 transition-colors"
                        aria-label="Notifications">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    {{-- Unread indicator — always in the DOM so realtime can reveal it. --}}
                    <span id="notif-dot" class="absolute top-1.5 right-1.5 flex {{ $unreadCount > 0 ? '' : 'hidden' }}">
                        <span class="absolute inline-flex w-2 h-2 rounded-full bg-iot-red opacity-75 animate-ping"></span>
                        <span class="relative inline-flex w-2 h-2 rounded-full bg-iot-red"></span>
                    </span>
                </button>
            </x-slot>
            <x-slot name="content">
                {{-- Panel header --}}
                <div class="flex items-center justify-between px-4 py-3 border-b border-iot-border">
                    <p class="text-sm font-medium text-white">Notifications</p>
                    @if($unreadCount > 0)
                        <span class="font-mono text-[10px] uppercase tracking-wider text-iot-accent
                                     bg-iot-accent/10 border border-iot-accent/20 px-2 py-0.5 rounded-full">
                            {{ $unreadCount }} new
                        </span>
                    @endif
                </div>

                {{-- Notification list (scrolls if it overflows) --}}
                <div class="max-h-80 overflow-y-auto">
                    @forelse($notifications as $note)
                        @php
                            $data = $note->data;
                            $accent = $severityAccent[$data['highest_severity'] ?? 'info'] ?? 'text-iot-muted';
                        @endphp
                        <a href="{{ $data['url'] ?? url('/alerts') }}"
                           class="flex items-start gap-3 px-4 py-3 border-b border-iot-border/60
                                  hover:bg-iot-surface2/60 transition-colors {{ $note->read_at ? 'opacity-60' : '' }}">
                            <span class="mt-0.5 flex-shrink-0 {{ $accent }}">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm text-white truncate">{{ $data['title'] ?? 'Device alert' }}</p>
                                @if(($data['count'] ?? 1) > 1)
                                    <p class="text-xs text-iot-muted mt-0.5">{{ $data['count'] }} devices affected</p>
                                @endif
                                <p class="text-[10px] text-iot-muted/70 mt-1 font-mono">{{ $note->created_at->diffForHumans() }}</p>
                            </div>
                        </a>
                    @empty
                        <div class="px-4 py-8 text-center text-sm text-iot-muted">
                            You're all caught up.
                        </div>
                    @endforelse
                </div>

                {{-- Panel footer --}}
                <div class="flex items-center justify-between px-4 py-2.5 border-t border-iot-border">
                    <form method="POST" action="{{ route('notifications.read') }}">
                        @csrf
                        <button type="submit"
                                class="text-xs font-medium text-iot-muted hover:text-white transition-colors">
                            Mark all read
                        </button>
                    </form>
                    <a href="{{ url('/alerts') }}"
                       class="text-xs font-medium text-iot-accent hover:text-white transition-colors">
                        View all
                    </a>
                </div>
            </x-slot>
        </x-dropdown>

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
                <x-dropdown-link :href="route('settings.notifications.edit')">
                    <svg class="w-4 h-4 mr-2 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    Notifications
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

@auth
{{-- Realtime bell: reveal the unread dot when a notification broadcasts on the
     user's private channel (window.Echo is compiled from resources/js/echo.js). --}}
<script>
(function () {
    if (typeof window.Echo === 'undefined') return;
    window.Echo.private('App.Models.User.{{ auth()->id() }}')
        .notification(function () {
            var dot = document.getElementById('notif-dot');
            if (dot) dot.classList.remove('hidden');
        });
})();
</script>
@endauth
