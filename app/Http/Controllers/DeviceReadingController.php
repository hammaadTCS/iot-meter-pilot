<?php

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeviceReadingController extends Controller
{
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
     * Returns [$windowStart, $windowEnd] where $windowEnd is null for preset ranges.
     * Returns null when a custom from/to pair is present but invalid.
     */
    private function resolveWindow(Request $request): ?array
    {
        if ($request->filled('from') && $request->filled('to')) {
            $start = rescue(fn () => Carbon::parse($request->query('from')), report: false);
            $end   = rescue(fn () => Carbon::parse($request->query('to')),   report: false);

            if (! $start || ! $end || $end->lte($start)) {
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
        $user = Auth::user();
        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403);
        }

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
        $user = Auth::user();
        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403);
        }

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
     * Debug endpoint — GET /api/devices/{device}/readings/debug
     * Shows row counts per range and the newest/oldest three rows.
     */
    public function debug(Device $device): JsonResponse
    {
        $table = self::TABLE;

        $totalRows = DB::table($table)->where('device_id', $device->id)->count();

        $rangeCounts = [];
        foreach (self::VALID_RANGES as $range) {
            $start = $this->windowStart($range);

            $rangeCounts[$range] = [
                'from'  => $start->toDateTimeString(),
                'count' => DB::table($table)
                    ->where('device_id', $device->id)
                    ->where(function ($q) use ($start) {
                        $q->where('received_at', '>=', $start)
                            ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '>=', $start));
                    })
                    ->count(),
            ];
        }

        $columns = ['id', 'ts', 'received_at', 'created_at', 'updated_at', 'voltage', 'current', 'power'];

        return response()->json([
            'device_id'       => $device->id,
            'device_name'     => $device->name,
            'table'           => $table,
            'recorded_at_sql' => self::RECORDED_AT_SQL,
            'total_rows'      => $totalRows,
            'server_time_now' => now()->toDateTimeString(),
            'range_counts'    => $rangeCounts,
            'newest_3_rows'   => DB::table($table)->where('device_id', $device->id)
                ->orderByRaw(self::RECORDED_AT_SQL . ' desc')->limit(3)->get($columns),
            'oldest_3_rows'   => DB::table($table)->where('device_id', $device->id)
                ->orderByRaw(self::RECORDED_AT_SQL . ' asc')->limit(3)->get($columns),
        ]);
    }
}
