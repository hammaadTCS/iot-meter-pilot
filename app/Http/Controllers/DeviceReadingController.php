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
    /**
     * Historical readings table used by the dashboard API.
     */
    private const TABLE = 'meter_readings';

    /**
     * Range queries are driven by the ingest timestamp when available.
     */
    private const RECORDED_AT_SQL = 'COALESCE(received_at, created_at)';

    /**
     * Cap large full-load responses so charts remain responsive.
     * 30d and all ranges may have thousands of rows — sampling keeps the
     * chart smooth while still showing the full trend shape.
     */
    private const MAX_ROWS = 500;

    /**
     * Allowed frontend range keys.
     */
    private const VALID_RANGES = ['1h', '6h', '24h', 'today', '7d', '30d', 'all'];

    /**
     * Convert a range key into the start of the requested window.
     */
    private function windowStart(string $range): Carbon
    {
        return match ($range) {
            '1h'  => now()->subHour(),
            '6h'  => now()->subHours(6),
            '24h' => now()->subDay(),
            'today' => Carbon::today(),
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            // 'all' uses Unix epoch so the window filter passes every row
            'all' => Carbon::createFromTimestamp(0),
            default => now()->subHour(),
        };
    }

    /**
     * Parse the recorded-at cursor sent by the dashboard. Invalid values are
     * ignored so the API can safely fall back to a full load.
     */
    private function parseCursorTimestamp(Request $request): ?Carbon
    {
        if (!$request->filled('after_received_at')) {
            return null;
        }

        return rescue(
            fn () => Carbon::parse((string) $request->query('after_received_at')),
            report: false,
        );
    }

    /**
     * Apply the selected dashboard window using `received_at` first, with a
     * fallback to `created_at` for rows created before the new column existed.
     */
    private function applyRecordedAtWindow($query, Carbon $windowStart): void
    {
        $query->where(function ($windowQuery) use ($windowStart) {
            $windowQuery->where('received_at', '>=', $windowStart)
                ->orWhere(function ($legacyQuery) use ($windowStart) {
                    $legacyQuery->whereNull('received_at')
                        ->where('created_at', '>=', $windowStart);
                });
        });
    }

    /**
     * Apply an incremental cursor using recorded-at time plus the row id as a
     * deterministic tie-breaker.
     */
    private function applyCursor($query, ?Carbon $afterRecordedAt, int $afterId): void
    {
        if ($afterRecordedAt) {
            $afterRecordedAtString = $afterRecordedAt->toDateTimeString();

            $query->where(function ($cursorQuery) use ($afterRecordedAtString, $afterId) {
                $cursorQuery->where(function ($newerQuery) use ($afterRecordedAtString) {
                    $newerQuery->where('received_at', '>', $afterRecordedAtString)
                        ->orWhere(function ($legacyQuery) use ($afterRecordedAtString) {
                            $legacyQuery->whereNull('received_at')
                                ->where('created_at', '>', $afterRecordedAtString);
                        });
                });

                if ($afterId > 0) {
                    $cursorQuery->orWhere(function ($tieBreakerQuery) use ($afterRecordedAtString, $afterId) {
                        $tieBreakerQuery->where(function ($sameTimeQuery) use ($afterRecordedAtString) {
                            $sameTimeQuery->where('received_at', '=', $afterRecordedAtString)
                                ->orWhere(function ($legacyQuery) use ($afterRecordedAtString) {
                                    $legacyQuery->whereNull('received_at')
                                        ->where('created_at', '=', $afterRecordedAtString);
                                });
                        })->where('id', '>', $afterId);
                    });
                }
            });

            return;
        }

        // Backward compatibility for callers still sending ?after=<id>.
        if ($afterId > 0) {
            $query->where('id', '>', $afterId);
        }
    }

    /**
     * Return readings filtered by dashboard range and optional incremental
     * cursor. The response keeps the `created_at` field name for frontend
     * compatibility, but its value is the effective recorded-at timestamp.
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $user = Auth::user();

        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403);
        }

        $rangeKey = $request->query('range', '1h');

        if (!in_array($rangeKey, self::VALID_RANGES, true)) {
            $rangeKey = '1h';
        }

        $windowStart = $this->windowStart($rangeKey);
        $afterId = max(0, (int) $request->query('after_id', $request->query('after', 0)));
        $afterRecordedAt = $this->parseCursorTimestamp($request);

        $query = DB::table(self::TABLE)
            ->where('device_id', $device->id)
            ->orderByRaw(self::RECORDED_AT_SQL.' asc')
            ->orderBy('id', 'asc')
            ->select(
                'id',
                'ts',
                DB::raw(self::RECORDED_AT_SQL.' as created_at'),
                'received_at',
                'voltage',
                'current',
                'power',
                'energy_computed_wh',
                'energy_pzem_wh',
                'frequency',
                'pf'
            );

        $this->applyRecordedAtWindow($query, $windowStart);
        $this->applyCursor($query, $afterRecordedAt, $afterId);

        $readings = $query->get();

        if (!$afterRecordedAt && $afterId === 0 && $readings->count() > self::MAX_ROWS) {
            $total = $readings->count();
            $step = (int) ceil($total / self::MAX_ROWS);

            $readings = $readings
                ->filter(fn ($row, int $index) => $index % $step === 0 || $index === $total - 1)
                ->values();
        }

        return response()->json($readings);
    }

    /**
     * Debug endpoint to inspect what the dashboard API sees for a device.
     */
    public function debug(Device $device): JsonResponse
    {
        $table = self::TABLE;

        $totalRows = DB::table($table)
            ->where('device_id', $device->id)
            ->count();

        $rangeCounts = [];
        foreach (self::VALID_RANGES as $range) {
            $start = $this->windowStart($range);

            $rangeCounts[$range] = [
                'from' => $start->toDateTimeString(),
                'count' => DB::table($table)
                    ->where('device_id', $device->id)
                    ->where(function ($windowQuery) use ($start) {
                        $windowQuery->where('received_at', '>=', $start)
                            ->orWhere(function ($legacyQuery) use ($start) {
                                $legacyQuery->whereNull('received_at')
                                    ->where('created_at', '>=', $start);
                            });
                    })
                    ->count(),
            ];
        }

        $columns = [
            'id',
            'ts',
            'received_at',
            'created_at',
            'updated_at',
            'voltage',
            'current',
            'power',
        ];

        $newest = DB::table($table)
            ->where('device_id', $device->id)
            ->orderByRaw(self::RECORDED_AT_SQL.' desc')
            ->limit(3)
            ->get($columns);

        $oldest = DB::table($table)
            ->where('device_id', $device->id)
            ->orderByRaw(self::RECORDED_AT_SQL.' asc')
            ->limit(3)
            ->get($columns);

        return response()->json([
            'device_id' => $device->id,
            'device_name' => $device->name,
            'table' => $table,
            'recorded_at_sql' => self::RECORDED_AT_SQL,
            'total_rows' => $totalRows,
            'server_time_now' => now()->toDateTimeString(),
            'range_counts' => $rangeCounts,
            'newest_3_rows' => $newest,
            'oldest_3_rows' => $oldest,
        ]);
    }
}
