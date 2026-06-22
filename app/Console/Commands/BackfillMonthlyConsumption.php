<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterMonthlyConsumption;
use App\Models\MeterReading;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild meter_monthly_consumption from historical meter_readings.
 *
 * Why this exists:
 * - The live aggregate (MeterPayloadProcessor::updateMonthlyConsumption) only
 *   grows from the moment it is deployed. Without a backfill, the current month
 *   would start from the first reading received *after* deploy instead of from
 *   last month's final reading — under-counting the month in progress.
 * - This command replays the stored history per device, applying the exact same
 *   baseline-chaining and reset (rollover) rules, so every past month and the
 *   current month are correct immediately.
 *
 * It is idempotent: it derives everything from history, so it can be re-run
 * safely (it rewrites each device's rows from scratch inside a transaction).
 *
 * Run once after migrating:  php artisan meters:backfill-monthly-consumption
 */
class BackfillMonthlyConsumption extends Command
{
    protected $signature = 'meters:backfill-monthly-consumption
                            {--device= : Limit the backfill to a single device id}';

    protected $description = 'Rebuild per-month meter consumption (units) from historical readings';

    public function handle(): int
    {
        $deviceQuery = Device::query();

        if ($this->option('device')) {
            $deviceQuery->whereKey((int) $this->option('device'));
        }

        $currentPeriod = now()->startOfMonth()->toDateString();
        $devicesProcessed = 0;
        $rowsWritten = 0;

        foreach ($deviceQuery->cursor() as $device) {
            $rows = $this->rebuildDevice($device, $currentPeriod);

            if ($rows > 0) {
                $devicesProcessed++;
                $rowsWritten += $rows;
            }
        }

        $this->line("Backfilled {$rowsWritten} monthly rows across {$devicesProcessed} device(s).");
        Log::info('Monthly consumption backfill complete', [
            'devices' => $devicesProcessed,
            'rows' => $rowsWritten,
        ]);

        return self::SUCCESS;
    }

    /**
     * Replay one device's history into monthly rows. Returns the number of
     * month rows written.
     */
    private function rebuildDevice(Device $device, string $currentPeriod): int
    {
        /**
         * Per-month accumulators keyed by period_start ("Y-m-d" of month start).
         * Each holds the same fields the live path maintains.
         *
         * @var array<string, array{baseline:int,last:int,rollover:int,reading_id:int,reading_at:string}> $months
         */
        $months = [];

        // The running "last counter" carried across month boundaries so each new
        // month's baseline is the previous month's final reading.
        $carryLast = null;

        // Stream readings oldest-first by effective receive time (the same clock
        // the dashboard and live aggregate use), tie-broken by id.
        $readings = MeterReading::query()
            ->where('device_id', $device->id)
            ->whereNotNull('energy_pzem_wh')
            ->orderByRaw('COALESCE(received_at, created_at) ASC')
            ->orderBy('id')
            ->cursor();

        foreach ($readings as $reading) {
            $energy = (int) $reading->energy_pzem_wh;
            $effectiveAt = $reading->received_at ?? $reading->created_at;
            $period = Carbon::parse($effectiveAt)->startOfMonth()->toDateString();

            if (! isset($months[$period])) {
                // New month: continue from the running counter, or seed the very
                // first month at its own reading (zero consumption to start).
                $months[$period] = [
                    'baseline'   => $carryLast ?? $energy,
                    'last'       => $energy,
                    'rollover'   => 0,
                    'reading_id' => (int) $reading->id,
                    'reading_at' => (string) $effectiveAt,
                ];
            } else {
                $m = &$months[$period];

                if ($energy < $m['last']) {
                    $m['rollover'] += $m['last']; // counter reset — bank pre-reset total
                }

                $m['last'] = $energy;
                $m['reading_id'] = (int) $reading->id;
                $m['reading_at'] = (string) $effectiveAt;
                unset($m);
            }

            $carryLast = $energy;
        }

        if ($months === []) {
            return 0;
        }

        // Persist atomically. Rewrite from scratch so re-runs stay idempotent.
        DB::transaction(function () use ($device, $months, $currentPeriod) {
            MeterMonthlyConsumption::where('device_id', $device->id)->delete();

            foreach ($months as $period => $m) {
                $row = new MeterMonthlyConsumption;
                $row->device_id = $device->id;
                $row->period_start = $period;
                $row->baseline_energy_wh = $m['baseline'];
                $row->last_energy_wh = $m['last'];
                $row->rollover_wh = $m['rollover'];
                $row->last_reading_id = $m['reading_id'];
                $row->last_reading_at = $m['reading_at'];
                // Every month except the calendar month in progress is closed.
                $row->finalized_at = $period < $currentPeriod ? $m['reading_at'] : null;
                $row->recomputeUnits();
                $row->save();
            }

            // Refresh the cached current-month figure on the latest state so the
            // KPI card is correct on the very first dashboard load.
            if (isset($months[$currentPeriod])) {
                $current = MeterMonthlyConsumption::where('device_id', $device->id)
                    ->whereDate('period_start', $currentPeriod)
                    ->value('units_kwh');

                LatestMeterState::where('device_id', $device->id)
                    ->update(['monthly_units_kwh' => $current]);
            }
        });

        return count($months);
    }
}
