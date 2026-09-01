<?php

namespace App\Console\Commands;

use App\Events\AlertOpened;
use App\Events\AlertResolved;
use App\Models\AlertEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Watch the machine, not the meters.
 *
 * ScanMeterHealth answers "is this device reporting?". Nothing answered "is the
 * system that collects the reports still healthy?" - so a filling disk was found
 * twice in one month by something breaking, and 574 failed jobs accumulated over
 * four days with nobody aware.
 *
 * Deliberately a sibling of ScanMeterHealth rather than a new subsystem: it emits
 * AlertEvent rows and fires AlertOpened / AlertResolved into the same delivery
 * pipeline, so it inherits coalescing, digests, the bell, and alerts:prune for free.
 *
 * System alerts carry device_id = NULL and device_type = 'system'.
 * EnqueueAlertForDelivery already guards `$alert->device &&` before reading an
 * owner, so they skip the customer-owner path and route to fleet operators holding
 * alerts.fleet_scope - the right audience for infrastructure problems.
 */
class ScanSystemHealth extends Command
{
    protected $signature = 'system:scan-health
                            {--dry-run : Report findings without opening or resolving alerts}';

    protected $description = 'Open and resolve system health alerts (disk, services, queue, logs)';

    /** Marks alerts that belong to the machine rather than to a device. */
    private const DEVICE_TYPE = 'system';

    public function handle(): int
    {
        $referenceTime = now();

        // Each check returns null when healthy, or the condition to alert on.
        $conditions = [
            'system_disk_space' => $this->checkDiskSpace(),
            'system_service_down' => $this->checkServices(),
            'system_failed_jobs' => $this->checkFailedJobs(),
            'system_log_size' => $this->checkLogSizes(),
        ];

        if ($this->option('dry-run')) {
            foreach ($conditions as $alertType => $condition) {
                $this->line($condition === null
                    ? "  OK       {$alertType}"
                    : sprintf('  %-8s %s — %s', strtoupper($condition['severity']), $alertType, $condition['message']));
            }

            return self::SUCCESS;
        }

        $opened = 0;
        $resolved = 0;

        foreach ($conditions as $alertType => $condition) {
            // syncAlert commits before returning, so the transition events fire
            // after commit and the queued delivery listener reads persisted rows.
            $result = $this->syncAlert($alertType, $condition, $referenceTime);

            foreach ($result['opened'] as $alert) {
                event(new AlertOpened($alert));
                $opened++;
            }

            foreach ($result['resolved'] as $alert) {
                event(new AlertResolved($alert));
                $resolved++;
            }
        }

        Log::info('System health scan complete', [
            'checked' => count($conditions),
            'opened' => $opened,
            'resolved' => $resolved,
        ]);

        $this->info("System health scan complete. opened={$opened} resolved={$resolved}");

        return self::SUCCESS;
    }

    /**
     * Open the alert if the condition holds and none is open; resolve any open
     * alert of this type once the condition clears.
     *
     * @param  array{severity: string, message: string, context: array}|null  $condition
     * @return array{opened: list<AlertEvent>, resolved: list<AlertEvent>}
     */
    private function syncAlert(string $alertType, ?array $condition, $referenceTime): array
    {
        return DB::transaction(function () use ($alertType, $condition, $referenceTime) {
            $open = AlertEvent::query()
                ->whereNull('device_id')
                ->where('alert_type', $alertType)
                ->where('status', 'open')
                ->lockForUpdate()
                ->get();

            if ($condition === null) {
                if ($open->isEmpty()) {
                    return ['opened' => [], 'resolved' => []];
                }

                AlertEvent::whereIn('id', $open->pluck('id'))->update([
                    'status' => 'resolved',
                    'resolved_at' => $referenceTime,
                    'updated_at' => $referenceTime,
                ]);

                return [
                    'opened' => [],
                    'resolved' => $open->each(fn (AlertEvent $a) => $a->forceFill([
                        'status' => 'resolved',
                        'resolved_at' => $referenceTime,
                    ]))->all(),
                ];
            }

            // Already open: leave it alone. Re-alerting every scan would turn a
            // single persisting condition into a notification flood.
            if ($open->isNotEmpty()) {
                return ['opened' => [], 'resolved' => []];
            }

            $alert = AlertEvent::create([
                'device_id' => null,
                'device_type' => self::DEVICE_TYPE,
                'alert_type' => $alertType,
                'severity' => $condition['severity'],
                'status' => 'open',
                'message' => $condition['message'],
                'context' => $condition['context'],
                'triggered_at' => $referenceTime,
            ]);

            return ['opened' => [$alert], 'resolved' => []];
        });
    }

    /**
     * @return array{severity: string, message: string, context: array}|null
     */
    private function checkDiskSpace(): ?array
    {
        $path = config('system-health.disk.path');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        // Unreadable path or an exotic filesystem: stay silent rather than raise a
        // false alarm about a number we could not obtain.
        if ($free === false || $total === false || $total <= 0) {
            return null;
        }

        $usedPercent = (int) round((($total - $free) / $total) * 100);
        $warning = config('system-health.disk.warning_percent');
        $critical = config('system-health.disk.critical_percent');

        if ($usedPercent < $warning) {
            return null;
        }

        return [
            'severity' => $usedPercent >= $critical ? 'critical' : 'warning',
            'message' => sprintf(
                'Disk space is low. %d%% of %s is used, %s free.',
                $usedPercent,
                $path,
                $this->humanBytes((int) $free),
            ),
            'context' => [
                'path' => $path,
                'used_percent' => $usedPercent,
                'free_bytes' => (int) $free,
                'total_bytes' => (int) $total,
                'warning_percent' => $warning,
                'critical_percent' => $critical,
            ],
        ];
    }

    /**
     * @return array{severity: string, message: string, context: array}|null
     */
    private function checkServices(): ?array
    {
        $services = config('system-health.services', []);

        if ($services === []) {
            return null;
        }

        $inactive = [];

        foreach ($services as $service) {
            try {
                $result = Process::timeout(10)->run("systemctl --user is-active {$service}");
                $state = trim($result->output());

                if ($state !== 'active') {
                    $inactive[$service] = $state !== '' ? $state : 'unknown';
                }
            } catch (Throwable $e) {
                // No systemd, or systemctl unavailable. Not knowing is not the same
                // as being down; a false "service down" page is worse than silence.
                return null;
            }
        }

        if ($inactive === []) {
            return null;
        }

        return [
            'severity' => 'critical',
            'message' => sprintf(
                'Supervised service not running: %s.',
                implode(', ', array_keys($inactive)),
            ),
            'context' => ['inactive' => $inactive],
        ];
    }

    /**
     * @return array{severity: string, message: string, context: array}|null
     */
    private function checkFailedJobs(): ?array
    {
        $count = DB::table('failed_jobs')->count();
        $warning = config('system-health.failed_jobs.warning');
        $critical = config('system-health.failed_jobs.critical');

        if ($count < $warning) {
            return null;
        }

        return [
            'severity' => $count >= $critical ? 'critical' : 'warning',
            'message' => sprintf(
                '%s jobs have failed and are not being retried. Alert notifications may not be reaching anyone.',
                number_format($count),
            ),
            'context' => [
                'failed_jobs' => $count,
                'warning' => $warning,
                'critical' => $critical,
            ],
        ];
    }

    /**
     * @return array{severity: string, message: string, context: array}|null
     */
    private function checkLogSizes(): ?array
    {
        $over = [];

        foreach (config('system-health.log_paths', []) as $relativePath => $limitBytes) {
            $absolute = base_path($relativePath);

            if (! is_dir($absolute)) {
                continue;
            }

            $size = $this->directorySize($absolute);

            if ($size > $limitBytes) {
                $over[$relativePath] = ['bytes' => $size, 'limit_bytes' => $limitBytes];
            }
        }

        if ($over === []) {
            return null;
        }

        $summary = [];
        foreach ($over as $path => $info) {
            $summary[] = sprintf(
                '%s is %s (limit %s)',
                $path,
                $this->humanBytes($info['bytes']),
                $this->humanBytes($info['limit_bytes']),
            );
        }

        return [
            'severity' => 'warning',
            'message' => 'Log storage is over its limit — a rotation or prune has probably stopped: '
                .implode('; ', $summary).'.',
            'context' => ['over_limit' => $over],
        ];
    }

    /**
     * Top-level file sizes only. Log directories are flat, and this avoids walking
     * a directory tree every five minutes.
     */
    private function directorySize(string $absolutePath): int
    {
        $total = 0;

        foreach ((glob(rtrim($absolutePath, '/').'/*') ?: []) as $file) {
            if (is_file($file)) {
                $total += (int) @filesize($file);
            }
        }

        return $total;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $unit) {
            if ($bytes < 1024 || $unit === 'TB') {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return $bytes.' B';
    }
}
