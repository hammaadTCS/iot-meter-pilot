<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
*/

// Private per-user channel — powers the realtime notification bell. Laravel
// notifications broadcast on App.Models.User.{id}; a user may only listen to
// their own channel.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private per-device telemetry channel — MeterReadingUpdated and
// MeterAvailabilityUpdated broadcast here. The predicate mirrors the dashboard
// exactly (DeviceDashboardController::show): meter.access is the master gate,
// meter.live_data is the section gate, and DevicePolicy::view carries the
// ownership rule, so there is no second copy of the authorization logic.
Broadcast::channel('device.{device}', function (User $user, Device $device) {
    return $user->can('view', $device)
        && $user->can('meter.access')
        && $user->can('meter.live_data');
});
