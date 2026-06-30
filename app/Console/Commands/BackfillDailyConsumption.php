<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\MeterDailyConsumption;
use App\Models\MeterReading;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild meter_daily_consumption from historical meter_readings.
 *
 * The daily counterpart of meters:backfill-monthly-consumption. The live
 * aggregate (MeterPayloadProcessor::updateDailyConsumption) only grows from the
 * moment it is deployed, so without a backfill the current day would start from
 * the first reading received *after* deploy instead of from the previous day's
 * final reading. This command replays stored history per device, applying the
 * exact same baseline-chaining and reset (rollover) rules, so every past day and
 * the day in progress are correct immediately.
 *
 * Idempotent: it derives everything from history and rewrites each device's rows
 * from scratch inside a transaction, so it can be re-run safely.
 *
 * Run once after migrating:  php artisan meters:backfill-daily-consumption
 */
class BackfillDailyConsumption extends Command
{
    protected $signature = 'meters:backfill-daily-consumption
                            {--device= : Limit the backfill to a single device id}';

    protected $description = 'Rebuild per-day meter consumption (units) from historical readings';

    public function handle(): int
    {
        $deviceQuery = Device::query();

        if ($this->option('device')) {
            $deviceQuery->whereKey((int) $this->option('device'));
        }

        $currentPeriod = now()->startOfDay()->toDateString();
        $devicesProcessed = 0;
        $rowsWritten = 0;

        foreach ($deviceQuery->cursor() as $device) {
            $rows = $this->rebuildDevice($device, $currentPeriod);

            if ($rows > 0) {
                $devicesProcessed++;
                $rowsWritten += $rows;
            }
        }

        $this->line("Backfilled {$rowsWritten} daily rows across {$devicesProcessed} device(s).");
        Log::info('Daily consumption backfill complete', [
            'devices' => $devicesProcessed,
            'rows' => $rowsWritten,
        ]);

        return self::SUCCESS;
    }

    /**
     * Replay one device's history into daily rows. Returns the number of day
     * rows written.
     */
    private function rebuildDevice(Device $device, string $currentPeriod): int
    {
        /**
         * Per-day accumulators keyed by period_date ("Y-m-d"). Each holds the
         * same fields the live path maintains.
         *
         * @var array<string, array{baseline:int,last:int,rollover:int,reading_id:int,reading_at:string}> $days
         */
        $days = [];

        // The running "last counter" carried across day boundaries so each new
        // day's baseline is the previous day's final reading.
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
            $period = Carbon::parse($effectiveAt)->startOfDay()->toDateString();

            if (! isset($days[$period])) {
                // New day: continue from the running counter, or seed the very
                // first day at its own reading (zero consumption to start).
                $days[$period] = [
                    'baseline'   => $carryLast ?? $energy,
                    'last'       => $energy,
                    'rollover'   => 0,
                    'reading_id' => (int) $reading->id,
                    'reading_at' => (string) $effectiveAt,
                ];
            } else {
                $d = &$days[$period];

                if ($energy < $d['last']) {
                    $d['rollover'] += $d['last']; // counter reset — bank pre-reset total
                }

                $d['last'] = $energy;
                $d['reading_id'] = (int) $reading->id;
                $d['reading_at'] = (string) $effectiveAt;
                unset($d);
            }

            $carryLast = $energy;
        }

        if ($days === []) {
            return 0;
        }

        // Persist atomically. Rewrite from scratch so re-runs stay idempotent.
        DB::transaction(function () use ($device, $days, $currentPeriod) {
            MeterDailyConsumption::where('device_id', $device->id)->delete();

            foreach ($days as $period => $d) {
                $row = new MeterDailyConsumption;
                $row->device_id = $device->id;
                $row->period_date = $period;
                $row->baseline_energy_wh = $d['baseline'];
                $row->last_energy_wh = $d['last'];
                $row->rollover_wh = $d['rollover'];
                $row->last_reading_id = $d['reading_id'];
                $row->last_reading_at = $d['reading_at'];
                // Every day except the calendar day in progress is closed.
                $row->finalized_at = $period < $currentPeriod ? $d['reading_at'] : null;
                $row->recomputeUnits();
                $row->save();
            }
        });

        return count($days);
    }
}
