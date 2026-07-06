<?php

namespace App\Console\Commands;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\AlertEvent;
use App\Models\Device;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanMeterHealth extends Command
{
    protected $signature = 'meters:scan-health {--device= : Scan one device id only}';

    protected $description = 'Create and resolve meter health alerts from telemetry freshness';

    public function handle(): int
    {
        $referenceTime = now();
        $query = Device::query()
            ->where('type', 'meter')
            ->with('alertSettings')   // offline_enabled opt-out, one query for the chunk
            ->orderBy('id');

        if ($this->option('device')) {
            $query->whereKey((int) $this->option('device'));
        }

        $scanned = 0;
        $opened = 0;
        $resolved = 0;

        $query->chunkById(100, function ($devices) use ($referenceTime, &$scanned, &$opened, &$resolved) {
            foreach ($devices as $device) {
                $scanned++;

                // syncDeviceAlerts commits its transaction before returning, so we
                // fire the transition events *after* commit — the (queued) delivery
                // listener can then safely read the persisted alert rows.
                $result = $this->syncDeviceAlerts($device, $referenceTime);

                foreach ($result['opened'] as $alert) {
                    event(new AlertOpened($alert));
                    $opened++;
                }
                foreach ($result['resolved'] as $alert) {
                    event(new AlertResolved($alert));
                    $resolved++;
                }
            }
        });

        $this->info("Meter health scan complete. Scanned={$scanned}, opened={$opened}, resolved={$resolved}.");

        Log::info('Meter health scan complete', [
            'scanned' => $scanned,
            'opened' => $opened,
            'resolved' => $resolved,
        ]);

        return self::SUCCESS;
    }

    /**
     * Keep one open alert per device/type, resolving stale states as telemetry
     * recovers or as the device moves into a more severe health state.
     *
     * Returns the AlertEvent models that actually transitioned this scan, so the
     * caller fires AlertOpened / AlertResolved exactly once per genuine change
     * (never per scan — that is the structural anti-spam guarantee).
     *
     * @return array{opened: list<AlertEvent>, resolved: list<AlertEvent>}
     */
    private function syncDeviceAlerts(Device $device, $referenceTime): array
    {
        return DB::transaction(function () use ($device, $referenceTime) {
            $opened = [];
            $resolved = [];

            /*
             * Per-meter opt-out: offline alerts are on by default (no settings
             * row = enabled), but a user can switch them off. Opting out also
             * resolves anything currently open, so the console/bell don't keep
             * showing an alert the user asked not to receive.
             */
            if ($device->alertSettings && ! $device->alertSettings->offline_enabled) {
                $resolved = array_merge(
                    $this->resolveOpenAlerts($device, 'telemetry_stale', $referenceTime),
                    $this->resolveOpenAlerts($device, 'telemetry_down', $referenceTime),
                );

                return ['opened' => $opened, 'resolved' => $resolved];
            }

            $health = $device->healthSnapshot($referenceTime);
            $status = $health['status'];

            if ($status === 'down') {
                $resolved = array_merge($resolved, $this->resolveOpenAlerts($device, 'telemetry_stale', $referenceTime));
                if ($event = $this->openAlertIfMissing($device, 'telemetry_down', 'critical', $health, $referenceTime)) {
                    $opened[] = $event;
                }

                return ['opened' => $opened, 'resolved' => $resolved];
            }

            if ($status === 'stale') {
                $resolved = array_merge($resolved, $this->resolveOpenAlerts($device, 'telemetry_down', $referenceTime));
                if ($event = $this->openAlertIfMissing($device, 'telemetry_stale', 'warning', $health, $referenceTime)) {
                    $opened[] = $event;
                }

                return ['opened' => $opened, 'resolved' => $resolved];
            }

            $resolved = array_merge($resolved, $this->resolveOpenAlerts($device, 'telemetry_stale', $referenceTime));
            $resolved = array_merge($resolved, $this->resolveOpenAlerts($device, 'telemetry_down', $referenceTime));

            return ['opened' => $opened, 'resolved' => $resolved];
        });
    }

    private function openAlertIfMissing(
        Device $device,
        string $alertType,
        string $severity,
        array $health,
        $referenceTime,
    ): ?AlertEvent {
        $existing = AlertEvent::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return null;
        }

        return AlertEvent::create([
            'device_id' => $device->id,
            'device_type' => $device->type,
            'alert_type' => $alertType,
            'severity' => $severity,
            'status' => 'open',
            'message' => $health['message'],
            'context' => [
                'health_status' => $health['status'],
                'last_seen_at' => $health['last_seen_at'],
                'seconds_since_last_seen' => $health['seconds_since_last_seen'],
                'stale_after_seconds' => $health['stale_after_seconds'],
                'down_after_seconds' => $health['down_after_seconds'],
            ],
            'triggered_at' => $referenceTime,
        ]);
    }

    /**
     * Resolve every open alert of a type for a device and return the resolved
     * models (so the caller can fire AlertResolved for each).
     *
     * @return list<AlertEvent>
     */
    private function resolveOpenAlerts(Device $device, string $alertType, $referenceTime): array
    {
        $open = AlertEvent::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->lockForUpdate()
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        AlertEvent::whereIn('id', $open->pluck('id'))->update([
            'status' => 'resolved',
            'resolved_at' => $referenceTime,
            'updated_at' => $referenceTime,
        ]);

        return $open->each(function (AlertEvent $alert) use ($referenceTime) {
            $alert->forceFill(['status' => 'resolved', 'resolved_at' => $referenceTime]);
        })->all();
    }
}
