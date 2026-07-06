<x-app-layout>
    <div class="max-w-2xl">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-white">Alerts — {{ $device->name }}</h1>
            <p class="text-sm text-iot-muted mt-1">
                Turn on the alerts you want for this meter and set their limits. Leave a field blank to disable it.
            </p>
        </div>

        @if(session('status') === 'alert-settings-updated')
            <div class="mb-4 px-4 py-2.5 rounded-lg border border-iot-green/30 bg-iot-green/10 text-iot-green text-sm">
                Alert settings saved.
            </div>
        @endif

        <form method="POST" action="{{ route('devices.alerts.update', $device) }}" class="space-y-6">
            @csrf
            @method('PATCH')

            {{-- ── Consumption ──────────────────────────────────────────────── --}}
            <div class="bg-iot-surface border border-iot-border rounded-xl p-6 space-y-5">
                <h2 class="text-sm font-semibold text-white uppercase tracking-wider">Consumption</h2>

                <div>
                    <label class="block text-sm text-white mb-1">Monthly budget</label>
                    <p class="text-xs text-iot-muted mb-2">Warn as you approach a monthly usage limit; escalates to critical when exceeded.</p>
                    <div class="flex items-center gap-2">
                        <input type="number" step="0.001" min="0" name="monthly_budget_kwh"
                               value="{{ old('monthly_budget_kwh', $settings->monthly_budget_kwh) }}" placeholder="e.g. 300"
                               class="w-32 bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                        <span class="text-iot-muted text-sm">kWh &nbsp;·&nbsp; warn at</span>
                        <input type="number" min="1" max="99" name="monthly_budget_warn_pct"
                               value="{{ old('monthly_budget_warn_pct', $settings->monthly_budget_warn_pct) }}"
                               class="w-20 bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                        <span class="text-iot-muted text-sm">%</span>
                    </div>
                    @error('monthly_budget_kwh') <p class="text-iot-red text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm text-white mb-1">Daily budget</label>
                    <p class="text-xs text-iot-muted mb-2">Warn when a single day's usage crosses this limit.</p>
                    <div class="flex items-center gap-2">
                        <input type="number" step="0.001" min="0" name="daily_budget_kwh"
                               value="{{ old('daily_budget_kwh', $settings->daily_budget_kwh) }}" placeholder="e.g. 12"
                               class="w-32 bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                        <span class="text-iot-muted text-sm">kWh</span>
                    </div>
                    @error('daily_budget_kwh') <p class="text-iot-red text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div>
                        <label class="block text-sm text-white mb-1">Unusual usage</label>
                        <p class="text-xs text-iot-muted">Alert when a day is well above your typical usage.</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <input type="checkbox" name="anomaly_enabled" value="1" @checked($settings->anomaly_enabled)
                               class="w-4 h-4 rounded border-iot-border bg-iot-surface2 text-iot-accent">
                        <input type="number" step="0.1" min="1.1" max="10" name="anomaly_multiplier"
                               value="{{ old('anomaly_multiplier', $settings->anomaly_multiplier) }}"
                               class="w-16 bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                        <span class="text-iot-muted text-sm">× normal</span>
                    </div>
                </div>
            </div>

            {{-- ── Electrical safety ────────────────────────────────────────── --}}
            <div class="bg-iot-surface border border-iot-border rounded-xl p-6 space-y-5">
                <h2 class="text-sm font-semibold text-white uppercase tracking-wider">Electrical safety</h2>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm text-white mb-1">Over-voltage</label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.1" min="0" max="1000" name="voltage_high"
                                   value="{{ old('voltage_high', $settings->voltage_high) }}" placeholder="e.g. 250"
                                   class="w-full bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                            <span class="text-iot-muted text-sm">V</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-white mb-1">Under-voltage</label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.1" min="0" max="1000" name="voltage_low"
                                   value="{{ old('voltage_low', $settings->voltage_low) }}" placeholder="e.g. 190"
                                   class="w-full bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                            <span class="text-iot-muted text-sm">V</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-white mb-1">Max power</label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" min="0" name="power_max_kw"
                                   value="{{ old('power_max_kw', $settings->power_max_kw) }}" placeholder="e.g. 5"
                                   class="w-full bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                            <span class="text-iot-muted text-sm">kW</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm text-white mb-1">Min power factor</label>
                        <input type="number" step="0.01" min="0" max="1" name="pf_min"
                               value="{{ old('pf_min', $settings->pf_min) }}" placeholder="e.g. 0.85"
                               class="w-full bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                    </div>
                </div>
                <p class="text-xs text-iot-muted">These raise a critical alert only after the reading stays out of range for a few samples (no false alarms from a momentary blip).</p>
            </div>

            {{-- ── Availability ─────────────────────────────────────────────── --}}
            <div class="bg-iot-surface border border-iot-border rounded-xl p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <label class="block text-sm text-white mb-1">Meter offline</label>
                        <p class="text-xs text-iot-muted">Alert when the meter stops reporting (stale after ~3 min, down after ~10 min).</p>
                    </div>
                    <input type="checkbox" name="offline_enabled" value="1" @checked($settings->offline_enabled)
                           class="w-4 h-4 rounded border-iot-border bg-iot-surface2 text-iot-accent">
                </div>
            </div>

            <div>
                <button type="submit"
                        class="px-4 py-2 rounded-lg bg-iot-accent text-iot-bg text-sm font-medium hover:opacity-90 transition-opacity">
                    Save alert settings
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
