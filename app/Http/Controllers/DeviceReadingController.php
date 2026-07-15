<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\MeterDailyConsumption;
use App\Models\MeterHourlyConsumption;
use App\Models\MeterMonthlyConsumption;
use App\Services\Meters\RangeConsumption;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceReadingController extends Controller
{
    use AuthorizesRequests;

    /** Readings table. */
    private const TABLE = 'meter_readings';

    /** Effective timestamp — prefers received_at, falls back to created_at for legacy rows. */
    private const RECORDED_AT_SQL = 'COALESCE(received_at, created_at)';

    /** Allowed preset range keys accepted by both endpoints. */
    private const VALID_RANGES = ['1h', '6h', '24h', 'today', '7d', '30d', 'all'];

    /**
     * Maximum data points the chart endpoint returns.
     * Keeps Chart.js rendering fast regardless of how many raw rows exist.
     * When the range contains more rows, they are evenly sampled down to this limit.
     */
    private const CHART_MAX_POINTS = 500;

    /** Rows per page returned by the table endpoint. */
    private const TABLE_PER_PAGE = 100;

    /**
     * Aggregate endpoint bucket rule: windows spanning at most this many hours
     * are served as hour buckets; anything longer as day buckets. 48h keeps
     * the largest hourly response at ~48 rows while giving the "24h"/"today"
     * presets full hourly resolution.
     */
    private const HOURLY_BUCKET_MAX_HOURS = 48;

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function windowStart(string $range): Carbon
    {
        return match ($range) {
            '1h'    => now()->subHour(),
            '6h'    => now()->subHours(6),
            '24h'   => now()->subDay(),
            'today' => Carbon::today(),
            '7d'    => now()->subDays(7),
            '30d'   => now()->subDays(30),
            'all'   => Carbon::createFromTimestamp(0),
            default => now()->subHour(),
        };
    }

    /**
     * Resolve the time window from the request.
     * Returns [$windowStart, $windowEnd] where $windowEnd is null for "live"
     * windows — preset ranges, and a custom `from` with no `to` (open-ended:
     * everything from the start up to now).
     * Returns null when a custom from/to is present but invalid.
     */
    private function resolveWindow(Request $request): ?array
    {
        if ($request->filled('from')) {
            $start = rescue(fn () => Carbon::parse($request->query('from')), report: false);

            if (! $start) {
                return null;
            }

            // No end given → open-ended window, up to now (live).
            if (! $request->filled('to')) {
                return [$start, null];
            }

            $end = rescue(fn () => Carbon::parse($request->query('to')), report: false);

            if (! $end || $end->lte($start)) {
                return null;
            }

            return [$start, $end];
        }

        $rangeKey = $request->query('range', '1h');
        if (! in_array($rangeKey, self::VALID_RANGES, true)) {
            $rangeKey = '1h';
        }

        return [$this->windowStart($rangeKey), null];
    }

    /**
     * Lower-bound time filter. Uses the composite (device_id, received_at) index
     * and falls back to created_at for rows that pre-date the received_at column.
     */
    private function applyRecordedAtWindow($query, Carbon $windowStart): void
    {
        $query->where(function ($q) use ($windowStart) {
            $q->where('received_at', '>=', $windowStart)
                ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '>=', $windowStart));
        });
    }

    /**
     * Upper-bound time filter — only needed for custom from/to ranges.
     */
    private function applyWindowEnd($query, Carbon $windowEnd): void
    {
        $query->where(function ($q) use ($windowEnd) {
            $q->where('received_at', '<=', $windowEnd)
                ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '<=', $windowEnd));
        });
    }

    /**
     * After-cursor filter — returns rows strictly NEWER than the cursor.
     * Used exclusively by the background-refresh polling path.
     */
    private function applyCursor($query, ?Carbon $afterRecordedAt, int $afterId): void
    {
        if ($afterRecordedAt) {
            $ts = $afterRecordedAt->toDateTimeString();

            $query->where(function ($q) use ($ts, $afterId) {
                $q->where(function ($newer) use ($ts) {
                    $newer->where('received_at', '>', $ts)
                        ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '>', $ts));
                });

                if ($afterId > 0) {
                    $q->orWhere(function ($tie) use ($ts, $afterId) {
                        $tie->where(function ($same) use ($ts) {
                            $same->where('received_at', '=', $ts)
                                ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '=', $ts));
                        })->where('id', '>', $afterId);
                    });
                }
            });

            return;
        }

        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }
    }

    /** Columns selected by all read endpoints. */
    private function readingColumns(): array
    {
        return [
            'id',
            'ts',
            DB::raw(self::RECORDED_AT_SQL . ' as created_at'),
            'received_at',
            'voltage',
            'current',
            'power',
            'energy_computed_wh',
            'energy_pzem_wh',
            'frequency',
            'pf',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public endpoints
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Chart endpoint — GET /api/devices/{device}/readings/chart
     *
     * Returns at most CHART_MAX_POINTS evenly-sampled raw rows ordered oldest-first.
     * When the range has more rows than the limit, only the row IDs are fetched first
     * (minimal memory), every Nth ID is picked, then the full data is fetched in a
     * single WHERE id IN (...) query — no full table scan of all columns.
     *
     * Accepts:
     *   ?range=1h|6h|24h|today|7d|30d|all   (preset window)
     *   ?from=<ISO>&to=<ISO>                 (custom window)
     */
    public function chart(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->can('meter.access') && $request->user()->can('meter.charts'), 403, 'Missing meter.charts permission.');

        $window = $this->resolveWindow($request);
        if (! $window) {
            return response()->json(['error' => 'Invalid from/to range.'], 422);
        }

        [$windowStart, $windowEnd] = $window;

        // Base query — window filter only, no column selection yet (we select IDs first).
        $baseQuery = DB::table(self::TABLE)->where('device_id', $device->id);
        $this->applyRecordedAtWindow($baseQuery, $windowStart);
        if ($windowEnd) {
            $this->applyWindowEnd($baseQuery, $windowEnd);
        }

        // Fetch IDs only — a bigint column is tiny even for hundreds of thousands of rows.
        $ids   = $baseQuery->orderByRaw(self::RECORDED_AT_SQL . ' ASC')->orderBy('id', 'ASC')->pluck('id');
        $total = $ids->count();

        if ($total === 0) {
            return response()->json([]);
        }

        if ($total <= self::CHART_MAX_POINTS) {
            // Small enough to return every row as-is.
            $sampledIds = $ids->all();
        } else {
            // Pick every Nth ID so the result spans the full time range evenly.
            $step       = (int) ceil($total / self::CHART_MAX_POINTS);
            $sampledIds = $ids
                ->filter(fn ($id, int $i) => $i % $step === 0 || $i === $total - 1)
                ->values()
                ->all();
        }

        // Fetch full column data only for the sampled IDs.
        $rows = DB::table(self::TABLE)
            ->whereIn('id', $sampledIds)
            ->orderByRaw(self::RECORDED_AT_SQL . ' ASC')
            ->orderBy('id', 'ASC')
            ->select($this->readingColumns())
            ->get();

        return response()->json($rows);
    }

    /**
     * Table endpoint — GET /api/devices/{device}/readings
     *
     * Two modes, detected automatically from the request params:
     *
     * Paginated mode (?range=X&page=N  or  ?from=X&to=Y&page=N):
     *   Returns { data: [...], meta: { current_page, last_page, per_page, total } }.
     *   Rows are newest-first so page 1 always shows the latest readings.
     *
     * Background-refresh mode (?after_received_at=T&after_id=N  or  ?after=N):
     *   Returns a plain JSON array, oldest-first, containing only rows newer than
     *   the cursor. This is the 30-second silent poll path and is fully backward-
     *   compatible with the existing dashboard JS.
     *
     * Accepts:
     *   ?range=1h|6h|24h|today|7d|30d|all   (preset window)
     *   ?from=<ISO>&to=<ISO>                 (custom window)
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->can('meter.access') && $request->user()->can('meter.history'), 403, 'Missing meter.history permission.');
        // Raw minute-level rows belong to the full operator dashboard only —
        // simplified-dashboard users read hour/day buckets via aggregate().
        abort_unless($request->user()->hasFullMeterDashboard(), 403, 'Raw readings require the full meter dashboard.');

        $window = $this->resolveWindow($request);
        if (! $window) {
            return response()->json(['error' => 'Invalid from/to range.'], 422);
        }

        [$windowStart, $windowEnd] = $window;

        $baseQuery = DB::table(self::TABLE)
            ->where('device_id', $device->id)
            ->select($this->readingColumns());

        $this->applyRecordedAtWindow($baseQuery, $windowStart);
        if ($windowEnd) {
            $this->applyWindowEnd($baseQuery, $windowEnd);
        }

        // ── Background-refresh path ───────────────────────────────────────────
        // Detected by the presence of any after-cursor param.
        // Returns a plain array (oldest-first) so existing dashboard JS needs no changes.
        $isRefresh = $request->filled('after_received_at')
            || $request->filled('after_id')
            || $request->filled('after');

        if ($isRefresh) {
            $afterId = max(0, (int) $request->query('after_id', $request->query('after', 0)));
            $afterRecordedAt = $request->filled('after_received_at')
                ? rescue(fn () => Carbon::parse($request->query('after_received_at')), report: false)
                : null;

            $baseQuery->orderByRaw(self::RECORDED_AT_SQL . ' ASC')->orderBy('id', 'ASC');
            $this->applyCursor($baseQuery, $afterRecordedAt, $afterId);

            return response()->json($baseQuery->get());
        }

        // ── Paginated path ────────────────────────────────────────────────────
        $perPage  = self::TABLE_PER_PAGE;
        $total    = (clone $baseQuery)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, (int) $request->query('page', 1)), $lastPage);
        $offset   = ($page - 1) * $perPage;

        $rows = $baseQuery
            ->orderByRaw(self::RECORDED_AT_SQL . ' DESC')
            ->orderBy('id', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $page,
                'last_page'    => $lastPage,
                'per_page'     => $perPage,
                'total'        => $total,
            ],
        ]);
    }

    /**
     * Aggregate history endpoint — GET /api/devices/{device}/readings/aggregate
     *
     * The simplified consumer dashboard's historical feed: pre-aggregated
     * hour/day buckets (units consumed + average voltage/power), never raw
     * minute-level rows. Reads only the incrementally-maintained rollups
     * (meter_hourly_consumption / meter_daily_consumption) — O(buckets), no
     * raw-readings scan, so it is safe on the 30s poll at fleet scale.
     *
     * Bucket resolution is chosen server-side: windows spanning at most
     * HOURLY_BUCKET_MAX_HOURS are served hourly, longer windows daily. A
     * short window older than the hourly retention (rows pruned) degrades
     * gracefully to day buckets. Day-bucket averages are derived from the
     * hour rows' exact sum/count accumulators (Σsum/Σcount), so they are
     * true means, not means-of-means; days beyond hourly retention keep
     * their units but report null averages.
     *
     * Guarded by meter.access + meter.history: this IS the history section
     * for simplified-dashboard users. Accepts the same window params as the
     * chart/table endpoints:
     *
     *   ?range=1h|6h|24h|today|7d|30d|all   (preset window)
     *   ?from=<ISO>&to=<ISO>                 (custom window; omit `to` for
     *                                         open-ended "up to now", live)
     */
    public function aggregate(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->can('meter.access') && $request->user()->can('meter.history'), 403, 'Missing meter.history permission.');

        $window = $this->resolveWindow($request);
        if (! $window) {
            return response()->json(['error' => 'Invalid from/to range.'], 422);
        }

        [$windowStart, $windowEnd] = $window;
        $effectiveEnd = $windowEnd ?? now();

        $bucket = $windowStart->diffInHours($effectiveEnd) <= self::HOURLY_BUCKET_MAX_HOURS
            ? 'hour'
            : 'day';

        $buckets = $bucket === 'hour'
            ? $this->hourBuckets($device->id, $windowStart, $effectiveEnd)
            : $this->dayBuckets($device->id, $windowStart, $effectiveEnd);

        // Hour rows are pruned after the retention window; a short window that
        // far back still has its day rollups (kept forever), so fall back.
        if ($bucket === 'hour' && $buckets->isEmpty()) {
            $fallback = $this->dayBuckets($device->id, $windowStart, $effectiveEnd);

            if ($fallback->isNotEmpty()) {
                $bucket  = 'day';
                $buckets = $fallback;
            }
        }

        return response()->json([
            'bucket'  => $bucket,
            'from'    => $windowStart->toDateTimeString(),
            'to'      => $windowEnd?->toDateTimeString(),
            'buckets' => $buckets,
        ]);
    }

    /**
     * Hour buckets for the window, oldest-first. The window is widened to the
     * enclosing hour boundaries so a 14:30 start still includes the 14:00
     * bucket the consumer would expect to see.
     *
     * @return \Illuminate\Support\Collection<int, array{period:string,units_kwh:float,avg_voltage:?float,avg_power:?float}>
     */
    private function hourBuckets(int $deviceId, Carbon $start, Carbon $end)
    {
        return MeterHourlyConsumption::query()
            ->where('device_id', $deviceId)
            ->where('period_start', '>=', $start->copy()->startOfHour())
            ->where('period_start', '<=', $end)
            ->orderBy('period_start')
            ->get()
            ->map(fn (MeterHourlyConsumption $row) => [
                'period'      => $row->period_start->format('Y-m-d H:i:s'),
                'units_kwh'   => (float) $row->units_kwh,
                'avg_voltage' => $row->averageVoltage(),
                'avg_power'   => $row->averagePower(),
            ])
            ->values();
    }

    /**
     * Day buckets for the window, oldest-first. Units come from the daily
     * rollup (the authoritative, never-pruned record); averages are derived
     * from the hour rows' accumulators grouped per calendar day.
     *
     * @return \Illuminate\Support\Collection<int, array{period:string,units_kwh:float,avg_voltage:?float,avg_power:?float}>
     */
    private function dayBuckets(int $deviceId, Carbon $start, Carbon $end)
    {
        $startDate = $start->copy()->startOfDay();

        // Σsum/Σcount per calendar day — exact means over the hour accumulators.
        // DATE() yields 'YYYY-MM-DD' on both MySQL and SQLite.
        $averagesByDay = DB::table('meter_hourly_consumption')
            ->where('device_id', $deviceId)
            ->where('period_start', '>=', $startDate)
            ->where('period_start', '<=', $end)
            ->groupByRaw('DATE(period_start)')
            ->selectRaw(
                'DATE(period_start) as day,'
                .' SUM(voltage_sum) as v_sum, SUM(voltage_count) as v_count,'
                .' SUM(power_sum) as p_sum, SUM(power_count) as p_count'
            )
            ->get()
            ->keyBy('day');

        return MeterDailyConsumption::query()
            ->where('device_id', $deviceId)
            ->whereDate('period_date', '>=', $startDate->toDateString())
            ->whereDate('period_date', '<=', $end->toDateString())
            ->orderBy('period_date')
            ->get()
            ->map(function (MeterDailyConsumption $row) use ($averagesByDay) {
                $day = $row->period_date->format('Y-m-d');
                $avg = $averagesByDay->get($day);

                return [
                    'period'      => $day,
                    'units_kwh'   => (float) $row->units_kwh,
                    'avg_voltage' => ($avg && (int) $avg->v_count > 0)
                        ? round((float) $avg->v_sum / (int) $avg->v_count, 1)
                        : null,
                    'avg_power'   => ($avg && (int) $avg->p_count > 0)
                        ? round((float) $avg->p_sum / (int) $avg->p_count, 1)
                        : null,
                ];
            })
            ->values();
    }

    /**
     * Consumption endpoint — GET /api/devices/{device}/readings/consumption
     *
     * Returns the energy consumed ("units", kWh) within the selected window,
     * computed by the shared RangeConsumption service — the single source of
     * truth, so this figure reconciles with the Monthly Units KPI and the
     * reports. Accepts the same window params as the chart/table endpoints:
     *
     *   ?range=1h|6h|24h|today|7d|30d|all   (preset window)
     *   ?from=<ISO>&to=<ISO>                 (custom window)
     */
    public function consumption(Request $request, Device $device): JsonResponse
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->can('meter.access'), 403, 'Missing meter.access permission.');

        $window = $this->resolveWindow($request);
        if (! $window) {
            return response()->json(['error' => 'Invalid from/to range.'], 422);
        }

        [$windowStart, $windowEnd] = $window;

        $result = RangeConsumption::unitsForWindow($device->id, $windowStart, $windowEnd);

        return response()->json([
            'units_kwh'     => $result['units_kwh'],
            'reading_count' => $result['reading_count'],
            'from'          => $windowStart->toDateTimeString(),
            'to'            => $windowEnd?->toDateTimeString(),
        ]);
    }

    /**
     * Daily consumption report — GET /api/devices/{device}/consumption/daily
     *
     * Returns the per-day units consumed for a calendar month plus that month's
     * total, both read from the pre-aggregated rollups (meter_daily_consumption
     * and meter_monthly_consumption) — never a raw-readings scan. Because the
     * rollups chain consistently, the daily rows sum to the monthly total.
     *
     *   ?month=YYYY-MM    (defaults to the current month)
     *   ?format=csv|json  (omit for a JSON object the dashboard renders)
     */
    public function dailyConsumption(Request $request, Device $device): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $device);
        abort_unless($request->user()->can('meter.access'), 403, 'Missing meter.access permission.');

        $month = $this->resolveMonth($request);
        if (! $month) {
            return response()->json(['error' => 'Invalid month (expected YYYY-MM).'], 422);
        }

        $monthEnd   = $month->copy()->endOfMonth();
        $monthLabel = $month->format('Y-m');

        // Per-day units from the daily rollup (pre-aggregated, no raw scan).
        $days = MeterDailyConsumption::query()
            ->where('device_id', $device->id)
            ->whereDate('period_date', '>=', $month->toDateString())
            ->whereDate('period_date', '<=', $monthEnd->toDateString())
            ->orderBy('period_date')
            ->get(['period_date', 'units_kwh'])
            ->map(fn ($r) => [
                'date'      => $r->period_date->format('Y-m-d'),
                'units_kwh' => (float) $r->units_kwh,
            ])
            ->values();

        // Authoritative monthly total (matches the Monthly Units KPI). Falls back
        // to the sum of the daily rows if the month row isn't present yet.
        $total = MeterMonthlyConsumption::query()
            ->where('device_id', $device->id)
            ->whereDate('period_start', $month->toDateString())
            ->value('units_kwh');
        $total = $total !== null ? (float) $total : round((float) $days->sum('units_kwh'), 3);

        $format = $request->query('format');

        if ($format === 'csv' || $format === 'json') {
            $filename = $this->exportFilename($device, $format, 'daily-consumption-' . $monthLabel);

            if ($format === 'json') {
                return response()->streamDownload(function () use ($days, $total, $monthLabel) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, json_encode(['type' => 'summary', 'month' => $monthLabel, 'total_units_kwh' => $total]) . PHP_EOL);
                    foreach ($days as $day) {
                        fwrite($out, json_encode($day) . PHP_EOL);
                    }
                    fclose($out);
                }, $filename, ['Content-Type' => 'application/x-ndjson']);
            }

            return response()->streamDownload(function () use ($days, $total, $monthLabel) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['date', 'units_kwh']);
                foreach ($days as $day) {
                    fputcsv($out, [$this->csvSafe($day['date']), $day['units_kwh']]);
                }
                fputcsv($out, ['TOTAL ' . $monthLabel, $total]);
                fclose($out);
            }, $filename, ['Content-Type' => 'text/csv']);
        }

        return response()->json([
            'month'           => $monthLabel,
            'total_units_kwh' => $total,
            'days'            => $days,
        ]);
    }

    /**
     * Resolve the report month from the request (YYYY-MM), defaulting to the
     * current month. Returns null for a malformed or impossible month.
     */
    private function resolveMonth(Request $request): ?Carbon
    {
        $month = $request->query('month');

        if ($month === null || $month === '') {
            return now()->startOfMonth();
        }

        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        $dt = rescue(fn () => Carbon::createFromFormat('Y-m-d', $month . '-01'), report: false);

        if (! $dt || $dt->format('Y-m') !== $month) {
            return null;
        }

        return $dt->startOfMonth();
    }

    /**
     * A safe download filename derived from the device code (sanitised to a slug
     * so a crafted code can't inject headers or path segments).
     */
    private function exportFilename(Device $device, string $format, string $label): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($device->code ?: ''));
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            $slug = 'device-' . $device->id;
        }

        $ext = $format === 'json' ? 'ndjson' : 'csv';

        return "meter-{$slug}-{$label}-" . now()->format('Ymd-His') . ".{$ext}";
    }

    /**
     * Neutralise CSV/formula injection: a cell beginning with a formula trigger
     * is prefixed with a single quote so spreadsheet apps treat it as text.
     */
    private function csvSafe(?string $value): string
    {
        $str = (string) $value;

        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }

        return $str;
    }

}
