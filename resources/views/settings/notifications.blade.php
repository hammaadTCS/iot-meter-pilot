<x-app-layout>
    <div class="max-w-2xl">
        <div class="mb-6">
            <h1 class="text-xl font-semibold text-white">Notification Settings</h1>
            <p class="text-sm text-iot-muted mt-1">
                Choose how you're notified about device alerts. In-app notifications (the bell) are always on.
            </p>
        </div>

        @if(session('status') === 'notification-preferences-updated')
            <div class="mb-4 px-4 py-2.5 rounded-lg border border-iot-green/30 bg-iot-green/10 text-iot-green text-sm">
                Preferences saved.
            </div>
        @endif

        <form method="POST" action="{{ route('settings.notifications.update') }}"
              class="bg-iot-surface border border-iot-border rounded-xl p-6 space-y-6">
            @csrf
            @method('PATCH')

            {{-- Email on/off --}}
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-white">Email notifications</p>
                    <p class="text-xs text-iot-muted mt-0.5">Send alert emails in addition to the in-app bell.</p>
                </div>
                <input type="checkbox" name="mail_enabled" value="1" @checked($prefs->mail_enabled)
                       class="w-4 h-4 rounded border-iot-border bg-iot-surface2 text-iot-accent">
            </div>

            {{-- Minimum email severity --}}
            <div>
                <label for="min_severity" class="block text-sm font-medium text-white mb-1">Email me for</label>
                <p class="text-xs text-iot-muted mb-2">The lowest severity that triggers an email.</p>
                <select name="min_severity" id="min_severity"
                        class="bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                    <option value="warning" @selected($prefs->min_severity === 'warning')>Warning and above (all alerts)</option>
                    <option value="critical" @selected($prefs->min_severity === 'critical')>Critical only</option>
                </select>
            </div>

            {{-- Quiet hours --}}
            <div>
                <p class="text-sm font-medium text-white mb-1">Quiet hours (optional)</p>
                <p class="text-xs text-iot-muted mb-2">During this window emails are held back; the in-app bell still updates.</p>
                <div class="flex items-center gap-2">
                    <input type="time" name="quiet_hours_start"
                           value="{{ $prefs->quiet_hours_start ? \Illuminate\Support\Carbon::parse($prefs->quiet_hours_start)->format('H:i') : '' }}"
                           class="bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                    <span class="text-iot-muted text-sm">to</span>
                    <input type="time" name="quiet_hours_end"
                           value="{{ $prefs->quiet_hours_end ? \Illuminate\Support\Carbon::parse($prefs->quiet_hours_end)->format('H:i') : '' }}"
                           class="bg-iot-surface2 border border-iot-border text-iot-text text-sm rounded-lg px-3 py-2">
                </div>
            </div>

            {{-- Fleet scope (admins only). Hidden 'own' default; the checkbox overrides to 'all' when ticked. --}}
            <input type="hidden" name="fleet_scope" value="own">
            @if($isAdmin)
                <div class="flex items-start justify-between gap-4 border-t border-iot-border pt-6">
                    <div>
                        <p class="text-sm font-medium text-white">Fleet-wide alerts</p>
                        <p class="text-xs text-iot-muted mt-0.5">Receive alerts for <em>all</em> devices, not just your own (digested). Admin only.</p>
                    </div>
                    <input type="checkbox" name="fleet_scope" value="all" @checked($prefs->fleet_scope === 'all')
                           class="w-4 h-4 rounded border-iot-border bg-iot-surface2 text-iot-accent">
                </div>
            @endif

            <div class="pt-2">
                <button type="submit"
                        class="px-4 py-2 rounded-lg bg-iot-accent text-iot-bg text-sm font-medium hover:opacity-90 transition-opacity">
                    Save preferences
                </button>
            </div>
        </form>
    </div>
</x-app-layout>
