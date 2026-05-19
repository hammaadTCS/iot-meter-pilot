<x-app-layout>
    <x-page-header
        :title="auth()->user()->isAdminOrAbove() ? 'All Devices' : 'My Devices'"
        subtitle="Monitor and manage your IoT devices.">
        <x-slot name="actions">
            <a href="{{ route('devices.create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium
                      bg-iot-accent text-iot-bg hover:bg-iot-accent/90 transition-colors">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                Add Device
            </a>
        </x-slot>
    </x-page-header>

    @if($devices->count() > 0)
        <div class="bg-iot-surface border border-iot-border rounded-2xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-iot-surface2 border-b border-iot-border">
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Name</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Code</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Type</th>
                            @if(auth()->user()->isAdminOrAbove())
                                <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Owner</th>
                            @endif
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted">Health</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted hidden md:table-cell">Availability</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted hidden lg:table-cell">Issue</th>
                            <th class="px-5 py-3.5 text-left font-mono text-[10px] uppercase tracking-widest text-iot-muted hidden xl:table-cell">Last Seen</th>
                            <th class="px-5 py-3.5 text-right font-mono text-[10px] uppercase tracking-widest text-iot-muted">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-iot-border">
                        @foreach($devices as $device)
                            @php
                                $health = $device->healthSnapshot();
                                $avail  = $device->availabilitySnapshot();
                                $issue  = $device->issueSnapshot();
                                $healthStatus = strtolower($health['label'] ?? 'unknown');
                                $availStatus  = strtolower($avail['label'] ?? 'unknown');
                                $issueStatus  = strtolower($issue['label'] ?? 'unknown');
                            @endphp
                            <tr class="hover:bg-iot-surface2 transition-colors">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-white">{{ $device->name }}</div>
                                    @if(!$device->is_active)
                                        <span class="text-[10px] font-mono text-iot-muted uppercase tracking-wider">Disabled</span>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="font-mono text-xs text-iot-muted">{{ $device->code }}</span>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="font-mono text-[10px] uppercase tracking-widest text-iot-muted
                                                 bg-iot-surface2 border border-iot-border px-2 py-1 rounded-lg">
                                        {{ str_replace('_', ' ', $device->type) }}
                                    </span>
                                </td>
                                @if(auth()->user()->isAdminOrAbove())
                                    <td class="px-5 py-4">
                                        @if($device->user)
                                            <div class="text-sm text-iot-text">{{ $device->user->name }}</div>
                                            <div class="text-xs text-iot-muted">{{ $device->user->email }}</div>
                                        @else
                                            <span class="text-iot-muted text-xs italic">Unassigned</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-5 py-4">
                                    <x-status-badge :status="$healthStatus" :label="$health['label']" />
                                </td>
                                <td class="px-5 py-4 hidden md:table-cell">
                                    <x-status-badge :status="$availStatus" :label="$avail['label']" />
                                </td>
                                <td class="px-5 py-4 hidden lg:table-cell">
                                    <x-status-badge :status="$issueStatus" :label="$issue['label']" />
                                </td>
                                <td class="px-5 py-4 text-xs text-iot-muted hidden xl:table-cell">
                                    {{ $device->last_seen_at ? $device->last_seen_at->diffForHumans() : '—' }}
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($device->type === 'meter')
                                            <a href="{{ route('devices.dashboard', $device) }}"
                                               class="px-3 py-1.5 rounded-lg text-xs font-medium
                                                      bg-iot-accent/10 text-iot-accent border border-iot-accent/20
                                                      hover:bg-iot-accent/20 transition-colors whitespace-nowrap">
                                                Dashboard
                                            </a>
                                        @endif
                                        <a href="{{ route('devices.edit', $device) }}"
                                           class="px-3 py-1.5 rounded-lg text-xs font-medium
                                                  text-iot-muted border border-iot-border
                                                  hover:text-white hover:bg-iot-surface2 transition-colors">
                                            Edit
                                        </a>
                                        @can('delete', $device)
                                            <form action="{{ route('devices.destroy', $device) }}" method="POST"
                                                  onsubmit="return confirm('Delete {{ addslashes($device->name) }}? This cannot be undone.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="px-3 py-1.5 rounded-lg text-xs font-medium
                                                               text-iot-red border border-red-500/20
                                                               hover:bg-red-500/10 transition-colors">
                                                    Delete
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="flex flex-col items-center justify-center py-20 text-center">
            <div class="w-16 h-16 rounded-2xl bg-iot-surface2 border border-iot-border
                        flex items-center justify-center mb-4">
                <svg class="w-8 h-8 text-iot-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                </svg>
            </div>
            <h3 class="font-mono text-base font-bold text-white mb-2">No devices found</h3>
            <p class="text-sm text-iot-muted mb-6">Add your first IoT device to start monitoring.</p>
            <a href="{{ route('devices.create') }}"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium
                      bg-iot-accent text-iot-bg hover:bg-iot-accent/90 transition-colors">
                + Add Device
            </a>
        </div>
    @endif
</x-app-layout>
