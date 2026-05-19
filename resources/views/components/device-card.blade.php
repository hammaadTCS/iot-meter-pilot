@props(['device', 'showOwner' => false])

@php
$iconMap = [
    'meter'      => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />',
    'sensor'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />',
    'camera'     => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.069A1 1 0 0121 8.87v6.26a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />',
    'thermostat' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z" /><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z" />',
    'smart_plug' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />',
    'lock'       => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />',
];
$iconPath = $iconMap[$device->type] ?? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />';

// Health status dot
$lastSeen = $device->last_seen_at;
$healthStatus = 'never_seen';
if ($lastSeen) {
    $minutesAgo = now()->diffInMinutes($lastSeen);
    $healthStatus = $minutesAgo <= 5 ? 'online' : ($minutesAgo <= 30 ? 'stale' : 'offline');
}

$dotColor = match($healthStatus) {
    'online'     => 'bg-iot-green shadow-[0_0_6px_#10b981] animate-pulse',
    'stale'      => 'bg-iot-amber',
    'offline'    => 'bg-iot-red',
    default      => 'bg-iot-muted',
};

$hasDashboard = $device->type === 'meter';
@endphp

<div class="bg-iot-surface border border-iot-border rounded-2xl p-5 flex flex-col gap-4
            hover:border-iot-accent/20 hover:shadow-lg hover:shadow-black/20 transition-all duration-150
            relative overflow-hidden group">

    {{-- Top accent line --}}
    <div class="absolute top-0 inset-x-0 h-0.5 bg-gradient-to-r from-iot-accent/40 to-iot-accent2/40
                opacity-0 group-hover:opacity-100 transition-opacity"></div>

    <div class="flex items-start justify-between gap-3">
        {{-- Icon + status dot --}}
        <div class="relative">
            <div class="w-10 h-10 rounded-xl bg-iot-surface2 border border-iot-border
                        flex items-center justify-center text-iot-muted group-hover:text-iot-accent transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    {!! $iconPath !!}
                </svg>
            </div>
            <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full border-2 border-iot-surface {{ $dotColor }}"></span>
        </div>

        {{-- Type badge --}}
        <span class="font-mono text-[10px] uppercase tracking-widest text-iot-muted
                     bg-iot-surface2 border border-iot-border px-2 py-1 rounded-lg">
            {{ str_replace('_', ' ', $device->type) }}
        </span>
    </div>

    <div class="flex-1 min-w-0">
        <h3 class="font-semibold text-white truncate">{{ $device->name }}</h3>
        <p class="font-mono text-xs text-iot-muted mt-0.5">{{ $device->code }}</p>
        @if($showOwner && $device->user)
            <p class="text-xs text-iot-muted mt-1 truncate">
                <span class="text-iot-text/60">Owner:</span> {{ $device->user->name }}
            </p>
        @endif
    </div>

    <div class="flex items-center justify-between pt-3 border-t border-iot-border">
        <div class="text-xs text-iot-muted">
            @if($lastSeen)
                {{ $lastSeen->diffForHumans() }}
            @else
                Never seen
            @endif
        </div>

        @if($hasDashboard)
            <a href="{{ route('devices.dashboard', $device) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
                      bg-iot-accent/10 text-iot-accent border border-iot-accent/20
                      hover:bg-iot-accent/20 transition-colors">
                Live Dashboard
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        @else
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium
                         text-iot-muted border border-iot-border cursor-default">
                Coming Soon
            </span>
        @endif
    </div>
</div>
