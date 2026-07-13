<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    class="fixed z-30 inset-y-0 left-0 w-64 flex flex-col
           bg-iot-surface border-r border-iot-border
           transform transition-transform duration-200 ease-in-out
           lg:static lg:inset-auto lg:translate-x-0"
>
    {{-- Brand --}}
    <div class="flex items-center gap-3 px-6 py-5 border-b border-iot-border flex-shrink-0">
        <span class="w-2 h-2 rounded-full bg-iot-accent shadow-[0_0_10px_#00e5ff] animate-pulse flex-shrink-0"></span>
        <span class="font-mono text-sm font-bold tracking-widest text-white uppercase">SmartHome</span>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">

        <x-sidebar-section label="Main">
            <x-sidebar-item :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Dashboard
            </x-sidebar-item>

            <x-sidebar-item :href="route('devices.index')" :active="request()->routeIs('devices.*')">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                </svg>
                Devices
            </x-sidebar-item>

            <x-sidebar-item :href="route('alerts.index')" :active="request()->routeIs('alerts.*')">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                </svg>
                Alerts
            </x-sidebar-item>
        </x-sidebar-section>

        @can('users.view_list')
        <x-sidebar-section label="Administration">
            <x-sidebar-item :href="route('users.index')" :active="request()->routeIs('users.*')">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Users
            </x-sidebar-item>
        </x-sidebar-section>
        @endcan

        <x-sidebar-section label="Account">
            <x-sidebar-item :href="route('profile.edit')" :active="request()->routeIs('profile.*')">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                Profile
            </x-sidebar-item>
        </x-sidebar-section>

    </nav>

    {{-- User identity footer --}}
    <div class="flex-shrink-0 px-4 py-4 border-t border-iot-border">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-iot-accent/20 border border-iot-accent/30
                        flex items-center justify-center flex-shrink-0">
                <span class="font-mono text-xs font-bold text-iot-accent">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name }}</p>
                <p class="text-xs text-iot-muted truncate">{{ auth()->user()->email }}</p>
            </div>
            @if(auth()->user()->hasRole('super_admin'))
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-mono font-bold
                             bg-iot-accent/15 text-iot-accent border border-iot-accent/30">SA</span>
            @endif
        </div>
    </div>
</aside>
