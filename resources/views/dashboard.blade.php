<x-app-layout>
    {{-- Stats row --}}
    @if($systemStats)
        {{-- Admin / Super Admin view --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <x-stats-card
                :value="$systemStats['total_users']"
                label="Total Users"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>'
                :href="route('users.index')"
            />
            <x-stats-card
                :value="$systemStats['total_devices']"
                label="Total Devices"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" /></svg>'
                :href="route('devices.index')"
            />
            <x-stats-card
                :value="$systemStats['online_devices']"
                label="Online Now"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z" /></svg>'
                color="iot-green"
            />
            <x-stats-card
                :value="$systemStats['active_devices']"
                label="Active Devices"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>'
                color="iot-accent"
            />
        </div>
    @else
        {{-- Regular user view --}}
        <div class="grid grid-cols-2 gap-4 mb-6">
            <x-stats-card
                :value="$myDevicesCount"
                label="My Devices"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" /></svg>'
                :href="route('devices.index')"
            />
            <x-stats-card
                :value="$myActiveCount"
                label="Active"
                icon='<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.636 18.364a9 9 0 010-12.728m12.728 0a9 9 0 010 12.728m-9.9-2.829a5 5 0 010-7.07m7.072 0a5 5 0 010 7.07M13 12a1 1 0 11-2 0 1 1 0 012 0z" /></svg>'
                color="iot-green"
            />
        </div>
    @endif

    {{-- Devices section --}}
    <div class="flex items-center justify-between mb-4">
        <h2 class="font-mono text-sm font-bold text-white uppercase tracking-widest">
            {{ auth()->user()->can('devices.view_any') ? 'Recent Devices' : 'My Devices' }}
        </h2>
        <a href="{{ route('devices.index') }}"
           class="text-xs text-iot-accent hover:text-iot-accent/80 transition-colors">
            View all →
        </a>
    </div>

    @if($recentDevices->count() > 0)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            @foreach($recentDevices as $device)
                <x-device-card :device="$device" :showOwner="auth()->user()->can('devices.view_any')" />
            @endforeach
        </div>
    @else
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-16 h-16 rounded-2xl bg-iot-surface2 border border-iot-border
                        flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-iot-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                </svg>
            </div>
            <h3 class="font-mono text-base font-bold text-white mb-2">No devices yet</h3>
            <p class="text-sm text-iot-muted mb-6 max-w-xs">
                Add your first IoT device to start monitoring your smart home.
            </p>
            <a href="{{ route('devices.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium
                      bg-iot-accent text-iot-bg hover:bg-iot-accent/90 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add First Device
            </a>
        </div>
    @endif
</x-app-layout>
