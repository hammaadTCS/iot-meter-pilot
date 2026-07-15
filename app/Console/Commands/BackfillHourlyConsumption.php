<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\MeterHourlyConsumption;
use App\Models\MeterReading;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rebuild meter_hourly_consumption from historical meter_readings.
 *
 * The hourly counterpart of meters:backfill-daily-consumption. The live
 * aggregate (MeterPayloadProcessor::updateHourlyConsumption) only grows from
 * the moment it is deployed, so without a backfill the simplified consumer
 * dashboard's hourly history would start empty. This command replays stored
 * history per device, applying the exact same baseline-chaining, reset
 * (rollover) and voltage/power-accumulator rules, so every past hour and the
 * hour in progress are correct immediately.
 *
 * Only the retention window is rebuilt (default 180 days, matching
 * meters:prune-hourly-consumption) — older hour rows would be pruned again
 * anyway. The units baseline still chains from the last reading BEFORE the
 * window so the first rebuilt hour is exact, not self-seeded.
 *
 * Idempotent: it derives everything from history and rewrites each device's
 * rows from scratch inside a transaction, so it can be re-run safely.
 *
 * Run once after migrating:  php artisan meters:backfill-hourly-consumption
 */
class BackfillHourlyConsumption extends Command
{
    protected $signature = 'meters:backfill-hourly-consumption
                            {--device= : Limit the backfill to a single device id}
                            {--days=180 : How many days of hour rows to rebuild (match the prune retention)}';

    protected $description = 'Rebuild per-hour meter consumption (units + V/W averages) from historical readings';

    public function handle(): int
    {
        $deviceQuery = Device::query();

        if ($this->option('device')) {
            $deviceQuery->whereKey((int) $this->option('device'));
        }

        $windowStart = now()->subDays(max(1, (int) $this->option('days')))->startOfHour();
        $currentPeriod = now()->startOfHour()->toDateTimeString();
        $devicesProcessed = 0;
        $rowsWritten = 0;

        foreach ($deviceQuery->cursor() as $device) {
            $rows = $this->rebuildDevice($device, $windowStart, $currentPeriod);

            if ($rows > 0) {
                $devicesProcessed++;
                $rowsWritten += $rows;
            }
        }

        $this->line("Backfilled {$rowsWritten} hourly rows across {$devicesProcessed} device(s).");
        Log::info('Hourly consumption backfill complete', [
            'devices' => $devicesProcessed,
            'rows' => $rowsWritten,
        ]);

        return self::SUCCESS;
    }

    /**
     * Replay one device's history into hour rows. Returns the number of hour
     * rows written.
     */
    private function rebuildDevice(Device $device, Carbon $windowStart, string $currentPeriod): int
    {
        /**
         * Per-hour accumulators keyed by period_start ("Y-m-d H:00:00"). Each
         * holds the same fields the live path maintains.
         *
         * @var array<string, array{baseline:int,last:int,rollover:int,v_sum:float,v_count:int,p_sum:float,p_count:int,reading_id:int,reading_at:string}> $hours
         */
        $hours = [];

        // Chain the first rebuilt hour's baseline from the last energy reading
        // before the window (exactly how the live path chains across hours).
        $carryLast = $this->lastEnergyBefore($device->id, $windowStart);

        // Stream readings oldest-first by effective receive time (the same clock
        // the dashboard and live aggregate use), tie-broken by id.
        $readings = MeterReading::query()
            ->where('device_id', $device->id)
            ->whereNotNull('energy_pzem_wh')
            ->where(function ($w) use ($windowStart) {
                $w->where('received_at', '>=', $windowStart)
                    ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '>=', $windowStart));
            })
            ->orderByRaw('COALESCE(received_at, created_at) ASC')
            ->orderBy('id')
            ->cursor();

        foreach ($readings as $reading) {
            $energy = (int) $reading->energy_pzem_wh;
            $effectiveAt = $reading->received_at ?? $reading->created_at;
            $period = Carbon::parse($effectiveAt)->startOfHour()->toDateTimeString();

            if (! isset($hours[$period])) {
                // New hour: continue from the running counter, or seed the very
                // first hour at its own reading (zero consumption to start).
                $hours[$period] = [
                    'baseline'   => $carryLast ?? $energy,
                    'last'       => $energy,
                    'rollover'   => 0,
                    'v_sum'      => 0.0,
                    'v_count'    => 0,
                    'p_sum'      => 0.0,
                    'p_count'    => 0,
                    'reading_id' => (int) $reading->id,
                    'reading_at' => (string) $effectiveAt,
                ];
            } else {
                $h = &$hours[$period];

                if ($energy < $h['last']) {
                    $h['rollover'] += $h['last']; // counter reset — bank pre-reset total
                }

                $h['last'] = $energy;
                $h['reading_id'] = (int) $reading->id;
                $h['reading_at'] = (string) $effectiveAt;
                unset($h);
            }

            if ($reading->voltage !== null) {
                $hours[$period]['v_sum'] += (float) $reading->voltage;
                $hours[$period]['v_count']++;
            }

            if ($reading->power !== null) {
                $hours[$period]['p_sum'] += (float) $reading->power;
                $hours[$period]['p_count']++;
            }

            $carryLast = $energy;
        }

        if ($hours === []) {
            return 0;
        }

        // Persist atomically. Rewrite from scratch so re-runs stay idempotent.
        DB::transaction(function () use ($device, $hours, $currentPeriod) {
            MeterHourlyConsumption::where('device_id', $device->id)->delete();

            foreach ($hours as $period => $h) {
                $row = new MeterHourlyConsumption;
                $row->device_id = $device->id;
                $row->period_start = $period;
                $row->baseline_energy_wh = $h['baseline'];
                $row->last_energy_wh = $h['last'];
                $row->rollover_wh = $h['rollover'];
                $row->voltage_sum = round($h['v_sum'], 3);
                $row->voltage_count = $h['v_count'];
                $row->power_sum = round($h['p_sum'], 3);
                $row->power_count = $h['p_count'];
                $row->last_reading_id = $h['reading_id'];
                $row->last_reading_at = $h['reading_at'];
                // Every hour except the clock hour in progress is closed.
                $row->finalized_at = $period < $currentPeriod ? $h['reading_at'] : null;
                $row->recomputeUnits();
                $row->save();
            }
        });

        return count($hours);
    }

    /**
     * The cumulative counter (Wh) of the last reading strictly before the
     * rebuild window, or null if the device has no earlier reading.
     */
    private function lastEnergyBefore(int $deviceId, Carbon $boundary): ?int
    {
        $value = DB::table('meter_readings')
            ->where('device_id', $deviceId)
            ->whereNotNull('energy_pzem_wh')
            ->where(function ($w) use ($boundary) {
                $w->where('received_at', '<', $boundary)
                    ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '<', $boundary));
            })
            ->orderByRaw('COALESCE(received_at, created_at) DESC')
            ->orderBy('id', 'DESC')
            ->value('energy_pzem_wh');

        return $value === null ? null : (int) $value;
    }
}
