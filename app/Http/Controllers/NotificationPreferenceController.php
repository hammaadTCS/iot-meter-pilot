<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationPreferenceController extends Controller
{
    /** Show the notification preferences form (existing row, or defaults). */
    public function edit(Request $request): View
    {
        return view('settings.notifications', [
            'prefs'   => NotificationPreference::forUser($request->user()),
            // Naming kept for the blade; the capability is now a permission.
            'isAdmin' => $request->user()->can('alerts.fleet_scope'),
        ]);
    }

    /** Persist the user's preferences. */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'min_severity'      => ['required', 'in:warning,critical'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end'   => ['nullable', 'date_format:H:i'],
            'fleet_scope'       => ['required', 'in:own,all'],
        ]);

        // Quiet hours are both-or-neither.
        $start = $validated['quiet_hours_start'] ?? null;
        $end   = $validated['quiet_hours_end'] ?? null;
        if (! $start || ! $end) {
            $start = $end = null;
        }

        // Fleet-wide delivery requires the alerts.fleet_scope permission —
        // otherwise a user could subscribe themselves to every device's
        // alerts. Force 'own' for everyone else.
        $fleetScope = ($validated['fleet_scope'] === 'all' && $user->can('alerts.fleet_scope')) ? 'all' : 'own';

        NotificationPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'mail_enabled'      => $request->boolean('mail_enabled'),
                'database_enabled'  => true, // in-app bell always on (must reflect reality)
                'broadcast_enabled' => true, // realtime bell always on
                'min_severity'      => $validated['min_severity'],
                'quiet_hours_start' => $start,
                'quiet_hours_end'   => $end,
                'fleet_scope'       => $fleetScope,
            ],
        );

        return back()->with('status', 'notification-preferences-updated');
    }
}
