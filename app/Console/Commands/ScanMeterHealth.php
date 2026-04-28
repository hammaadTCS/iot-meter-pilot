<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\MeterAlertEvent;
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
                [$openedNow, $resolvedNow] = $this->syncDeviceAlerts($device, $referenceTime);
                $opened += $openedNow;
                $resolved += $resolvedNow;
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
     */
    private function syncDeviceAlerts(Device $device, $referenceTime): array
    {
        return DB::transaction(function () use ($device, $referenceTime) {
            $health = $device->healthSnapshot($referenceTime);
            $status = $health['status'];
            $opened = 0;
            $resolved = 0;

            if ($status === 'down') {
                $resolved += $this->resolveOpenAlert($device, 'telemetry_stale', $referenceTime);
                $opened += $this->openAlertIfMissing($device, 'telemetry_down', 'critical', $health, $referenceTime);

                return [$opened, $resolved];
            }

            if ($status === 'stale') {
                $resolved += $this->resolveOpenAlert($device, 'telemetry_down', $referenceTime);
                $opened += $this->openAlertIfMissing($device, 'telemetry_stale', 'warning', $health, $referenceTime);

                return [$opened, $resolved];
            }

            $resolved += $this->resolveOpenAlert($device, 'telemetry_stale', $referenceTime);
            $resolved += $this->resolveOpenAlert($device, 'telemetry_down', $referenceTime);

            return [$opened, $resolved];
        });
    }

    private function openAlertIfMissing(
        Device $device,
        string $alertType,
        string $severity,
        array $health,
        $referenceTime,
    ): int {
        $existing = MeterAlertEvent::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return 0;
        }

        MeterAlertEvent::create([
            'device_id' => $device->id,
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

        return 1;
    }

    private function resolveOpenAlert(Device $device, string $alertType, $referenceTime): int
    {
        return MeterAlertEvent::query()
            ->where('device_id', $device->id)
            ->where('alert_type', $alertType)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_at' => $referenceTime,
                'updated_at' => $referenceTime,
            ]);
    }
}
