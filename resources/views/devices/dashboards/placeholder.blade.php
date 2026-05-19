<x-app-layout>
    <x-page-header :title="$device->name">
        <x-slot name="actions">
            <a href="{{ route('devices.edit', $device) }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium
                      bg-iot-surface2 text-iot-muted border border-iot-border
                      hover:text-white hover:bg-iot-border transition-colors">
                Manage Device
            </a>
        </x-slot>
    </x-page-header>

    <div class="flex flex-col items-center justify-center py-24 text-center max-w-md mx-auto">
        <div class="w-20 h-20 rounded-2xl bg-iot-surface2 border border-iot-border
                    flex items-center justify-center mb-6">
            @php
                $iconMap = [
                    'sensor'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />',
                    'camera'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />',
                    'thermostat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z" />',
                    'smart_plug' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />',
                    'lock'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />',
                ];
                $iconPath = $iconMap[$device->type] ?? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />';
            @endphp
            <svg class="w-10 h-10 text-iot-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                {!! $iconPath !!}
            </svg>
        </div>

        <span class="inline-block font-mono text-[10px] uppercase tracking-widest text-iot-muted
                     bg-iot-surface2 border border-iot-border px-3 py-1.5 rounded-lg mb-4">
            {{ str_replace('_', ' ', $device->type) }}
        </span>

        <h2 class="font-mono text-2xl font-bold text-white mb-3">{{ $device->name }}</h2>

        @if(isset($reason) && $reason === 'disabled')
            <p class="text-iot-amber text-sm mb-6">This device is currently disabled. Enable it to start monitoring.</p>
        @else
            <p class="text-iot-muted text-sm mb-6">
                Live dashboard for <strong class="text-iot-text">{{ str_replace('_', ' ', $device->type) }}</strong> devices is coming soon.<br>
                The device is registered and will be connected once the dashboard is ready.
            </p>
        @endif

        {{-- Status badges --}}
        <div class="flex flex-wrap items-center justify-center gap-3 mb-8">
            @php
                $health = $device->healthSnapshot();
                $avail  = $device->availabilitySnapshot();
                $healthStatus = strtolower($health['label'] ?? 'unknown');
                $availStatus  = strtolower($avail['label'] ?? 'unknown');
            @endphp
            <div class="flex items-center gap-2 bg-iot-surface2 border border-iot-border rounded-lg px-3 py-2">
                <span class="font-mono text-[10px] uppercase tracking-widest text-iot-muted">Health</span>
                <x-status-badge :status="$healthStatus" :label="$health['label']" />
            </div>
            <div class="flex items-center gap-2 bg-iot-surface2 border border-iot-border rounded-lg px-3 py-2">
                <span class="font-mono text-[10px] uppercase tracking-widest text-iot-muted">Signal</span>
                <x-status-badge :status="$availStatus" :label="$avail['label']" />
            </div>
        </div>

        <div class="flex gap-3">
            <a href="{{ route('devices.index') }}"
               class="px-5 py-2.5 rounded-xl text-sm font-medium bg-iot-surface2 text-iot-muted border border-iot-border
                      hover:text-white hover:bg-iot-border transition-colors">
                ← Back to Devices
            </a>
            @can('update', $device)
                <a href="{{ route('devices.edit', $device) }}"
                   class="px-5 py-2.5 rounded-xl text-sm font-medium bg-iot-accent/10 text-iot-accent border border-iot-accent/20
                          hover:bg-iot-accent/20 transition-colors">
                    Edit Device
                </a>
            @endcan
        </div>
    </div>
</x-app-layout>
