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
     * Allowed frontend range keys.
     */
    private const VALID_RANGES = ['1h', '6h', '24h', 'today', '7d', '30d', 'all'];

    /**
     * Convert a range key into the start of the requested window.
     */
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
     * Parse the after-cursor timestamp sent by the background refresh.
     */
    private function parseCursorTimestamp(Request $request): ?Carbon
    {
        if (! $request->filled('after_received_at')) {
            return null;
        }

        return rescue(
            fn () => Carbon::parse((string) $request->query('after_received_at')),
            report: false,
        );
    }

    /**
     * Parse the before-cursor timestamp sent by paginated chunk requests.
     */
    private function parseBeforeCursorTimestamp(Request $request): ?Carbon
    {
        if (! $request->filled('before_received_at')) {
            return null;
        }

        return rescue(
            fn () => Carbon::parse((string) $request->query('before_received_at')),
            report: false,
        );
    }

    /**
     * Apply the lower-bound window filter using the composite (device_id, received_at)
     * index with a fallback to created_at for legacy rows where received_at is null.
     */
    private function applyRecordedAtWindow($query, Carbon $windowStart): void
    {
        $query->where(function ($q) use ($windowStart) {
            $q->where('received_at', '>=', $windowStart)
                ->orWhere(function ($leg) use ($windowStart) {
                    $leg->whereNull('received_at')
                        ->where('created_at', '>=', $windowStart);
                });
        });
    }

    /**
     * Apply an upper-bound window filter (used for custom date/time ranges).
     */
    private function applyWindowEnd($query, Carbon $windowEnd): void
    {
        $query->where(function ($q) use ($windowEnd) {
            $q->where('received_at', '<=', $windowEnd)
                ->orWhere(function ($leg) use ($windowEnd) {
                    $leg->whereNull('received_at')
                        ->where('created_at', '<=', $windowEnd);
                });
        });
    }

    /**
     * Apply an after-cursor (gets rows NEWER than the cursor) for background refresh.
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

    /**
     * Apply a before-cursor (gets rows OLDER than the cursor) for paginated chunk loading.
     */
    private function applyBeforeCursor($query, ?Carbon $beforeRecordedAt, int $beforeId): void
    {
        if ($beforeRecordedAt) {
            $ts = $beforeRecordedAt->toDateTimeString();

            $query->where(function ($q) use ($ts, $beforeId) {
                $q->where(function ($older) use ($ts) {
                    $older->where('received_at', '<', $ts)
                        ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '<', $ts));
                });

                if ($beforeId > 0) {
                    $q->orWhere(function ($tie) use ($ts, $beforeId) {
                        $tie->where(function ($same) use ($ts) {
                            $same->where('received_at', '=', $ts)
                                ->orWhere(fn ($leg) => $leg->whereNull('received_at')->where('created_at', '=', $ts));
                        })->where('id', '<', $beforeId);
                    });
                }
            });

            return;
        }

        if ($beforeId > 0) {
            $query->where('id', '<', $beforeId);
        }
    }

    /**
     * Return readings filtered by either a preset range key or a custom from/to window.
     *
     * Paginated mode (activated by ?limit= or ?before_* params):
     *   Returns { data: [...], meta: { has_more, next_before_received_at, next_before_id } }
     *   Rows are ordered newest-first. Each page's oldest row becomes the next cursor.
     *
     * Non-paginated mode (background refresh via ?after_* params):
     *   Returns a plain JSON array of rows ordered oldest-first. Zero breaking change.
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        $user = Auth::user();

        if (! $user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403);
        }

        // ── Resolve window bounds ──────────────────────────────────────────────
        $hasCustomRange = $request->filled('from') && $request->filled('to');
        $windowEnd      = null;

        if ($hasCustomRange) {
            $windowStart = rescue(fn () => Carbon::parse($request->query('from')), report: false);
            $windowEnd   = rescue(fn () => Carbon::parse($request->query('to')),   report: false);

            if (! $windowStart || ! $windowEnd || $windowEnd->lte($windowStart)) {
                return response()->json(['error' => 'Invalid from/to range.'], 422);
            }
        } else {
            $rangeKey    = $request->query('range', '1h');
            if (! in_array($rangeKey, self::VALID_RANGES, true)) {
                $rangeKey = '1h';
            }
            $windowStart = $this->windowStart($rangeKey);
        }

        // ── Shared column select ───────────────────────────────────────────────
        $baseQuery = DB::table(self::TABLE)
            ->where('device_id', $device->id)
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

        $this->applyRecordedAtWindow($baseQuery, $windowStart);

        if ($windowEnd) {
            $this->applyWindowEnd($baseQuery, $windowEnd);
        }

        // ── Paginated branch (progressive chunked loading) ─────────────────────
        $isPaginated = $request->filled('limit')
            || $request->filled('before_received_at')
            || $request->filled('before_id');

        if ($isPaginated) {
            $limit          = min(500, max(1, (int) $request->query('limit', 500)));
            $beforeId       = max(0, (int) $request->query('before_id', 0));
            $beforeRecordedAt = $this->parseBeforeCursorTimestamp($request);

            if ($beforeRecordedAt || $beforeId > 0) {
                $this->applyBeforeCursor($baseQuery, $beforeRecordedAt, $beforeId);
            }

            $rows = $baseQuery
                ->orderByRaw(self::RECORDED_AT_SQL.' DESC')
                ->orderBy('id', 'DESC')
                ->limit($limit + 1)   // look-ahead: one extra to detect has_more
                ->get();

            $hasMore = $rows->count() > $limit;

            if ($hasMore) {
                $rows = $rows->slice(0, $limit);
            }

            $oldest             = $rows->last();
            $nextBeforeRecordedAt = ($hasMore && $oldest)
                ? ($oldest->received_at ?? $oldest->created_at)
                : null;
            $nextBeforeId       = ($hasMore && $oldest) ? $oldest->id : null;

            return response()->json([
                'data' => $rows->values(),
                'meta' => [
                    'has_more'                => $hasMore,
                    'next_before_received_at' => $nextBeforeRecordedAt,
                    'next_before_id'          => $nextBeforeId,
                ],
            ]);
        }

        // ── Non-paginated branch (background refresh after-cursor) ─────────────
        $afterId         = max(0, (int) $request->query('after_id', $request->query('after', 0)));
        $afterRecordedAt = $this->parseCursorTimestamp($request);

        $baseQuery
            ->orderByRaw(self::RECORDED_AT_SQL.' ASC')
            ->orderBy('id', 'ASC');

        $this->applyCursor($baseQuery, $afterRecordedAt, $afterId);

        return response()->json($baseQuery->get());
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
            'device_id'       => $device->id,
            'device_name'     => $device->name,
            'table'           => $table,
            'recorded_at_sql' => self::RECORDED_AT_SQL,
            'total_rows'      => $totalRows,
            'server_time_now' => now()->toDateTimeString(),
            'range_counts'    => $rangeCounts,
            'newest_3_rows'   => $newest,
            'oldest_3_rows'   => $oldest,
        ]);
    }
}
