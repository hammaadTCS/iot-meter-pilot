<?php

namespace App\Services\Meters;

use App\Models\MeterDailyConsumption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "energy consumed (units) over a time window".
 *
 * Every consumer of a consumption figure — the Range Units KPI, CSV/JSON
 * exports, and the range/monthly reports — calls this service, so they can never
 * disagree with each other.
 *
 * Semantics: consumption over [start, end] is the rise of the cumulative PZEM
 * counter across the window, self-contained (baseline = first reading inside the
 * window) and reset-aware (a counter drop banks the pre-reset total). This is
 * byte-for-byte the walk used by MeterMonthlyConsumption::recomputeUnits().
 *
 * Performance — the tiered strategy:
 *   A naive walk would scan every raw reading in the window on every call. For
 *   large windows polled every 30s across 10k+ devices that does not scale, and
 *   raw readings are pruned at ~90 days. So a multi-day window is computed from
 *   the incrementally-maintained daily rollup instead:
 *
 *       units = first-partial-day (raw, self-contained)
 *             + Σ interior whole-day buckets   (meter_daily_consumption)
 *             + last-partial-day  (raw, anchored to the previous day's last reading)
 *
 *   The daily buckets chain their baseline from the previous day's final
 *   reading, so the three segments telescope to exactly the same figure a
 *   continuous raw walk would produce. Every range becomes O(days) + two bounded
 *   (≤ 24h) edge scans instead of O(readings).
 *
 *   A single calendar-day window is walked directly (already cheap and exact).
 *   If the window's start day has no readings, a full raw walk is used as a
 *   safe, exact fallback (it avoids mis-anchoring interior buckets to a reading
 *   from before the window).
 *
 *   Note on a measure-zero edge case: a hardware counter reset landing exactly
 *   on a midnight day boundary follows the daily-bucket (clamp-to-zero)
 *   convention — identical to how the existing daily/monthly aggregates already
 *   treat period-boundary resets.
 */
class RangeConsumption
{
    /** Effective timestamp — prefers received_at, falls back to created_at for legacy rows. */
    private const RECORDED_AT_SQL = 'COALESCE(received_at, created_at)';

    /**
     * Units (kWh) consumed by a device within [$start, $end].
     *
     * @param  int          $deviceId  the device whose readings to measure
     * @param  Carbon       $start     inclusive lower bound (effective timestamp)
     * @param  Carbon|null  $end       inclusive upper bound, or null for "up to now"
     * @return array{units_kwh: float, reading_count: int}
     */
    public static function unitsForWindow(int $deviceId, Carbon $start, ?Carbon $end = null): array
    {
        $start = $start->copy();
        $end   = $end ? $end->copy() : Carbon::now();

        $consumedWh = self::consumedWhForWindow($deviceId, $start, $end);
        $count      = self::countReadings($deviceId, $start, $end);

        return [
            // Integer Wh up to here; round once. Wh/1000 is exact to 3 dp.
            'units_kwh'     => round(max(0, $consumedWh) / 1000, 3),
            'reading_count' => $count,
        ];
    }

    /**
     * Consumed Wh over the window, choosing the cheapest exact source.
     */
    private static function consumedWhForWindow(int $deviceId, Carbon $start, Carbon $end): int
    {
        $startDay = $start->copy()->startOfDay();
        $endDay   = $end->copy()->startOfDay();

        // Single calendar day (or inverted) → exact raw walk, cheap.
        if ($startDay->equalTo($endDay) || $end->lessThan($start)) {
            return self::rawWalk($deviceId, $start, $end, null)[0];
        }

        // First partial day: [start, end of start day], self-contained baseline.
        [$firstWh, $firstCount] = self::rawWalk($deviceId, $start, $startDay->copy()->endOfDay(), null);

        // If the start day has no readings the window effectively starts later;
        // a full raw walk stays exact and avoids mis-anchoring interior buckets
        // to a reading from before the window.
        if ($firstCount === 0) {
            return self::rawWalk($deviceId, $start, $end, null)[0];
        }

        // Interior whole days (startDay, endDay) exclusive → sum stored buckets.
        // Each bucket chains its baseline from the previous day's final reading,
        // so consecutive buckets telescope. Pure integer Wh, no rounding.
        $interiorWh = MeterDailyConsumption::query()
            ->where('device_id', $deviceId)
            ->whereDate('period_date', '>', $startDay->toDateString())
            ->whereDate('period_date', '<', $endDay->toDateString())
            ->get(['baseline_energy_wh', 'last_energy_wh', 'rollover_wh'])
            ->reduce(
                fn (int $carry, $r): int => $carry
                    + max(0, (int) $r->last_energy_wh - (int) $r->baseline_energy_wh + (int) $r->rollover_wh),
                0,
            );

        // Last partial day: [start of end day, end], anchored to the last reading
        // before the end day so it telescopes with the interior buckets.
        $anchor = self::lastEnergyBefore($deviceId, $endDay);
        $lastWh = self::rawWalk($deviceId, $endDay, $end, $anchor)[0];

        return $firstWh + $interiorWh + $lastWh;
    }

    /**
     * Reset-aware walk over the readings in [$start, $end].
     *
     * With $explicitBaseline null the walk is self-contained (the first reading
     * becomes the baseline). With an explicit baseline the walk is anchored to a
     * value from outside the window (used for the last partial day), and a first
     * in-window reading below that baseline is correctly treated as a reset.
     *
     * @return array{0: int, 1: int}  [consumed Wh (>= 0), reading count]
     */
    private static function rawWalk(int $deviceId, Carbon $start, Carbon $end, ?int $explicitBaseline): array
    {
        $rows = self::windowQuery($deviceId, $start, $end)
            ->orderByRaw(self::RECORDED_AT_SQL . ' ASC')
            ->orderBy('id', 'ASC')
            ->select('energy_pzem_wh')
            ->cursor();

        $baseline = $explicitBaseline;
        $last     = $explicitBaseline ?? 0;
        $rollover = 0;
        $count    = 0;

        foreach ($rows as $row) {
            $energy = (int) $row->energy_pzem_wh;
            $count++;

            if ($baseline === null) {
                $baseline = $energy;
                $last     = $energy;
                continue;
            }

            if ($energy < $last) {
                $rollover += $last;
            }

            $last = $energy;
        }

        if ($baseline === null) {
            return [0, 0];
        }

        return [max(0, $last - $baseline + $rollover), $count];
    }

    /**
     * Count of energy readings within the window (exact; used for reading_count).
     */
    private static function countReadings(int $deviceId, Carbon $start, Carbon $end): int
    {
        return self::windowQuery($deviceId, $start, $end)->count();
    }

    /**
     * The cumulative counter (Wh) of the last reading strictly before $boundary,
     * or null if the device has no earlier reading.
     */
    private static function lastEnergyBefore(int $deviceId, Carbon $boundary): ?int
    {
        $value = DB::table('meter_readings')
            ->where('device_id', $deviceId)
            ->whereNotNull('energy_pzem_wh')
            ->where(function ($w) use ($boundary) {
                $w->where('received_at', '<', $boundary)
                    ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '<', $boundary));
            })
            ->orderByRaw(self::RECORDED_AT_SQL . ' DESC')
            ->orderBy('id', 'DESC')
            ->value('energy_pzem_wh');

        return $value === null ? null : (int) $value;
    }

    /**
     * Base query: a device's energy readings within [$start, $end], using the
     * same legacy-null-received_at fallback the read endpoints use so figures
     * align with the chart/table exactly.
     */
    private static function windowQuery(int $deviceId, Carbon $start, Carbon $end)
    {
        return DB::table('meter_readings')
            ->where('device_id', $deviceId)
            ->whereNotNull('energy_pzem_wh')
            ->where(function ($w) use ($start) {
                $w->where('received_at', '>=', $start)
                    ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '>=', $start));
            })
            ->where(function ($w) use ($end) {
                $w->where('received_at', '<=', $end)
                    ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '<=', $end));
            });
    }
}
