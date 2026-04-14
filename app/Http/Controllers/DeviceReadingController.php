<?php
// =============================================================================
// app/Http/Controllers/DeviceReadingController.php
// =============================================================================
//
// PURPOSE:
//   Serves the AJAX API consumed by the meter dashboard Blade view.
//   Returns a JSON array of device readings filtered by time range
//   and an optional "after" cursor (for incremental background refreshes).
//
// YOUR TABLE STRUCTURE (from the database dump you shared):
//   id, device_id, ts (unix int), voltage, current, power,
//   energy_computed_wh, energy_pzem_wh, frequency, pf,
//   raw_payload, created_at (datetime), updated_at (datetime)
//
// IMPORTANT:
//   We filter by `created_at` (MySQL DATETIME column, e.g. 2026-03-12 04:35:49)
//   NOT by `ts` (Unix integer like 1773290149).
//   Carbon's now()->subHour() returns a datetime which compares correctly
//   against a DATETIME column. If you ever switch to filtering by `ts`,
//   you must compare against Unix timestamps instead (time() - 3600 etc).
//
// ROUTES — add BOTH to routes/api.php:
//   Route::get('/devices/{device}/readings',       [DeviceReadingController::class, 'index']);
//   Route::get('/devices/{device}/readings/debug', [DeviceReadingController::class, 'debug']);
//
// =============================================================================

namespace App\Http\Controllers;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeviceReadingController extends Controller
{
    // -------------------------------------------------------------------------
    // CONFIGURATION — edit these if your setup differs
    // -------------------------------------------------------------------------

    /**
     * The exact name of your readings table in the database.
     * From your dump: the table is called 'device_readings'.
     * Change this if your table has a different name.
     */
    private const TABLE = 'meter_readings';

    /**
     * Maximum rows returned in a single full-load response.
     * Prevents huge JSON payloads on the 7-day range.
     * Rows are evenly downsampled if this limit is exceeded.
     * Background refreshes (?after=N) bypass this limit entirely
     * because they only ever return a handful of new rows.
     */
    private const MAX_ROWS = 500;

    /**
     * Valid range keys the frontend is allowed to send.
     * Anything else silently falls back to '1h'.
     */
    private const VALID_RANGES = ['1h', '6h', '24h', 'today', '7d'];

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Convert a range key into the Carbon datetime for the START of that window.
     *
     * PHP does not allow function calls inside class constants (that was the
     * previous bug), so we use a method with match() instead.
     *
     * '24h'   = rolling 24 hours back from right now
     * 'today' = midnight (00:00:00) of the current calendar day
     *
     * @param  string  $range
     * @return Carbon
     */
    private function windowStart(string $range): Carbon
    {
        return match ($range) {
            '1h'    => now()->subHour(),
            '6h'    => now()->subHours(6),
            '24h'   => now()->subDay(),
            'today' => Carbon::today(),       // 00:00:00 today
            '7d'    => now()->subDays(7),
            default => now()->subHour(),
        };
    }

    // -------------------------------------------------------------------------
    // MAIN ACTION
    // -------------------------------------------------------------------------

    /**
     * Return readings filtered by time range and optional cursor.
     *
     * GET /api/devices/{device}/readings
     *
     * Query parameters:
     *   range  (string) — 1h | 6h | 24h | today | 7d    [default: 1h]
     *   after  (int)    — only return rows where id > N   [default: 0 = all]
     *
     * Response: JSON array, oldest → newest
     * [
     *   { "id":1, "created_at":"2026-03-12 04:35:49", "voltage":236.8, ... },
     *   ...
     * ]
     *
     * @param  Request  $request
     * @param  Device   $device   Route Model Binding resolves this automatically
     * @return JsonResponse
     */
    public function index(Request $request, Device $device): JsonResponse
    {
        // ── 1. Parse and validate the range ───────────────────────────────────

        $rangeKey = $request->query('range', '1h');

        if (!in_array($rangeKey, self::VALID_RANGES, strict: true)) {
            $rangeKey = '1h';  // silently fix invalid input
        }

        // Carbon datetime — e.g. "2026-03-12 03:35:49" for a 1h range
        // This is compared against the `created_at` DATETIME column in MySQL.
        $windowStart = $this->windowStart($rangeKey);

        // ── 2. Parse the incremental cursor ───────────────────────────────────

        // afterId = 0 means "full load, give me everything in the range"
        // afterId > 0 means "background refresh, only give me rows I haven't seen"
        $afterId = max(0, (int) $request->query('after', 0));

        // ── 3. Build the query ─────────────────────────────────────────────────

        $query = DB::table(self::TABLE)
            // Only readings for this specific device
            ->where('device_id', $device->id)

            // Filter to the time window using the DATETIME column `created_at`
            // Note: Carbon objects are automatically cast to the correct SQL
            // datetime string by Laravel's query builder (e.g. "2026-03-12 03:35:49")
            ->where('created_at', '>=', $windowStart)

            // Sort oldest → newest so charts can be plotted left → right
            // Use id as a tie-breaker in case two rows share the same timestamp
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')

            // Only select the columns the dashboard actually needs.
            // This keeps the JSON response small and fast.
            ->select(
                'id',                    // Required: used as the ?after= cursor
                'created_at',            // Required: chart X-axis and table timestamp
                'voltage',
                'current',
                'power',
                'energy_computed_wh',
                'energy_pzem_wh',
                'frequency',
                'pf'
            );

        // ── 4. Apply the incremental cursor for background refreshes ──────────

        if ($afterId > 0) {
            // Only return rows the frontend has NOT seen yet.
            // Example: lastKnownId = 42, new rows 43, 44, 45 arrived.
            // The frontend only receives 3 rows instead of re-fetching all 500.
            $query->where('id', '>', $afterId);
        }

        // ── 5. Execute the query ───────────────────────────────────────────────

        $readings = $query->get();

        // ── 6. Downsample if needed (full loads only) ──────────────────────────
        //
        // A 7-day range at 1 reading/minute = ~10,080 rows.
        // Sending all of them to the browser makes Chart.js slow and wastes
        // bandwidth. Instead, we keep every Nth row so the chart shape is
        // preserved but the payload stays small.
        //
        // Downsampling is SKIPPED for background refreshes (afterId > 0)
        // because they only return a few new rows anyway.
        if ($afterId === 0 && $readings->count() > self::MAX_ROWS) {
            $total = $readings->count();
            $step  = (int) ceil($total / self::MAX_ROWS);

            // Keep row at index 0, $step, 2*$step, ...
            // Always include the LAST row so charts end at the true latest value
            $readings = $readings
                ->filter(fn ($row, int $index) => $index % $step === 0 || $index === $total - 1)
                ->values();  // re-index so JSON serialises as an array, not object
        }

        // ── 7. Return JSON ─────────────────────────────────────────────────────
        return response()->json($readings);
    }

    // -------------------------------------------------------------------------
    // DEBUG ACTION  — REMOVE IN PRODUCTION
    // -------------------------------------------------------------------------

    /**
     * Debug endpoint to verify the API is working correctly.
     * Visit this URL in your browser to see what the API returns:
     *
     *   /api/devices/1/readings/debug
     *
     * It shows:
     *   - The exact SQL query being run
     *   - How many rows exist in total for this device
     *   - How many rows fall within each time range
     *   - The first 3 rows so you can verify column names
     *
     * DELETE this method and its route once everything is working.
     *
     * @param  Device  $device
     * @return JsonResponse
     */
    public function debug(Device $device): JsonResponse
    {
        $table = self::TABLE;

        // Count total rows for this device
        $totalRows = DB::table($table)
            ->where('device_id', $device->id)
            ->count();

        // Count rows in each range window
        $rangeCounts = [];
        foreach (self::VALID_RANGES as $range) {
            $start = $this->windowStart($range);
            $rangeCounts[$range] = [
                'from'  => $start->toDateTimeString(),
                'count' => DB::table($table)
                    ->where('device_id', $device->id)
                    ->where('created_at', '>=', $start)
                    ->count(),
            ];
        }

        // Fetch the 3 most recent rows to show column structure
        $sample = DB::table($table)
            ->where('device_id', $device->id)
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        // Fetch the 3 oldest rows
        $oldest = DB::table($table)
            ->where('device_id', $device->id)
            ->orderBy('created_at', 'asc')
            ->limit(3)
            ->get();

        return response()->json([
            'device_id'       => $device->id,
            'device_name'     => $device->name,
            'table'           => $table,
            'total_rows'      => $totalRows,
            'server_time_now' => now()->toDateTimeString(),
            'range_counts'    => $rangeCounts,
            'newest_3_rows'   => $sample,
            'oldest_3_rows'   => $oldest,
        ]);
    }
}
