<x-app-layout>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-white">Alerts</h1>
        <p class="text-sm text-iot-muted mt-1">
            Device health alerts you can see — you view your own devices; admins view the whole fleet.
        </p>
    </div>

    {{-- Filters (auto-submit) --}}
    <form method="GET" class="flex flex-wrap items-center gap-2 mb-4">
        <select name="status" onchange="this.form.submit()"
                class="bg-iot-surface border border-iot-border text-iot-text text-sm rounded-lg px-3 py-1.5">
            <option value="">All statuses</option>
            <option value="open" @selected($filters['status'] === 'open')>Open</option>
            <option value="resolved" @selected($filters['status'] === 'resolved')>Resolved</option>
        </select>
        <select name="severity" onchange="this.form.submit()"
                class="bg-iot-surface border border-iot-border text-iot-text text-sm rounded-lg px-3 py-1.5">
            <option value="">All severities</option>
            <option value="critical" @selected($filters['severity'] === 'critical')>Critical</option>
            <option value="warning" @selected($filters['severity'] === 'warning')>Warning</option>
        </select>
    </form>

    <div class="bg-iot-surface border border-iot-border rounded-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-iot-muted text-[10px] uppercase tracking-wider border-b border-iot-border">
                        <th class="px-4 py-3">Severity</th>
                        <th class="px-4 py-3">Device</th>
                        <th class="px-4 py-3">Alert</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Triggered</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        @php
                            $sevClass = $alert->severity === 'critical'
                                ? 'text-iot-red border-iot-red/30 bg-iot-red/10'
                                : 'text-iot-amber border-iot-amber/30 bg-iot-amber/10';
                            $statusClass = $alert->status === 'open'
                                ? 'text-iot-amber border-iot-amber/30 bg-iot-amber/10'
                                : 'text-iot-green border-iot-green/30 bg-iot-green/10';
                        @endphp
                        <tr class="border-b border-iot-border/60 hover:bg-iot-surface2/40">
                            <td class="px-4 py-3">
                                <span class="font-mono text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $sevClass }}">
                                    {{ $alert->severity }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-white">
                                {{ $alert->device?->name ?? 'Device #'.$alert->device_id }}
                                <span class="text-iot-muted text-xs">({{ $alert->device_type }})</span>
                            </td>
                            <td class="px-4 py-3 text-iot-muted">{{ $alert->message }}</td>
                            <td class="px-4 py-3">
                                <span class="font-mono text-[10px] uppercase tracking-wider px-2 py-0.5 rounded-full border {{ $statusClass }}">
                                    {{ $alert->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-iot-muted font-mono text-xs">
                                {{ optional($alert->triggered_at)->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-iot-muted">No alerts to show.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $alerts->links() }}
    </div>
</x-app-layout>
