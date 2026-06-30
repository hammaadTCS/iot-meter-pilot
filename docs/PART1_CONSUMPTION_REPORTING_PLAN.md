# Part 1 — Consumption & Reporting (Production Plan)

## Context

The meter system is feature-complete through **monthly consumption** (the `meter_monthly_consumption`
table, the Monthly Units KPI, the 12-month bar chart). Part 1 finishes the energy/units story with three
deliverables:

1. **Range Units KPI** — kWh consumed in the *currently selected* dashboard range (1H/6H/24H/Today/7D/30D/Full/custom).
2. **CSV / JSON export** — download readings + a consumption summary for a range.
3. **Monthly Energy Summary report** — a real per-month consumption report + export.

**Production goal:** memory-efficient, reliable, and secure at the platform's 10K+ device target — not just
correct for a single meter.

### The decision that shapes everything

Computing units by scanning raw `meter_readings` on every range change **and every 30s poll, per open
dashboard** does not scale — cursor streaming bounds PHP memory but not database load, and raw data is pruned
at ~90 days so long-range history can't be recomputed from it. Therefore Part 1 introduces a **daily
consumption rollup** (`meter_daily_consumption`), maintained the same proven incremental way the monthly
table already is. Every range then resolves to `sum(whole-day buckets) + ≤2 bounded partial-day edge scans`.

The single source of truth for all consumption math is one service: **`RangeConsumption`**. The KPI, the
export summary, and the report all call it, so they can never disagree — and a reconciliation test fails the
moment any second copy of the algorithm drifts.

---

## Architecture: tiered `RangeConsumption`

`app/Services/Meters/RangeConsumption.php` (new) — one entry point:

```php
RangeConsumption::unitsForWindow(int $deviceId, Carbon $start, ?Carbon $end): array
// → ['units_kwh' => float, 'reading_count' => int]
```

It selects the cheapest correct source for the window:

| Window | Source | Cost |
|---|---|---|
| Short (≤ ~1 day: 1h/6h/24h/today) | Direct cursor walk over raw `meter_readings` | bounded rows |
| Month-aligned (this month, last N months) | Read precomputed `meter_monthly_consumption` | O(months) |
| Large arbitrary (7d/30d/custom spanning days) | `meter_daily_consumption` buckets + partial-day edges | O(days) |

**The reset-aware walk** (used by the raw-scan path and the rollup maintainer) is byte-for-byte the algorithm
already in `MeterMonthlyConsumption::recomputeUnits()` and `MeterPayloadProcessor::updateMonthlyConsumption()`:

```
baseline = first non-null energy_pzem_wh at/after window start
last = baseline ; rollover = 0
foreach later non-null energy e (oldest→newest):
    if e < last: rollover += last          // PZEM/device hardware reset
    last = e
units_kwh = round(max(0, last − baseline + rollover) / 1000, 3)
```

**Edge stitching for large ranges:** full days come from stored bucket `units_kwh`; the start/end partial days
are bounded direct walks (each ≤24h). The current (still-accumulating) day is never read from a finalized
bucket — it is always computed live. Self-contained baseline semantics (baseline = first reading inside the
window) match the Jun 23 Range-Units decision; the seam handling lives entirely inside the service and is
proven by the reconciliation test (below).

**Query hygiene (memory):** the raw walk selects only `energy_pzem_wh`, `id`, and the recorded-at expression
— never all columns — and iterates with `->cursor()`. It reuses the controller's existing
`COALESCE(received_at, created_at)` predicate so figures align with the chart exactly.

---

## The daily rollup — `meter_daily_consumption`

Mirror the existing `meter_monthly_consumption` design and lifecycle exactly.

**Migration** — `database/migrations/<ts>_create_meter_daily_consumption_table.php`:

| column | type | notes |
|---|---|---|
| id | id | |
| device_id | foreignId → devices | cascadeOnDelete |
| period_date | date | the day in Asia/Karachi |
| baseline_energy_wh | unsignedBigInteger nullable | cumulative at day start |
| last_energy_wh | unsignedBigInteger nullable | latest cumulative that day |
| rollover_wh | unsignedBigInteger default 0 | pre-reset total |
| units_kwh | decimal(14,3) default 0 | = max(0,(last−baseline+rollover)/1000) |
| last_reading_id | unsignedBigInteger nullable | |
| last_reading_at | timestamp nullable | |
| finalized_at | timestamp nullable | set when the day closes |
| timestamps | | |

Indexes: `unique(['device_id','period_date'])`, `index('period_date')`.

**Model** — `app/Models/MeterDailyConsumption.php`: same shape as `MeterMonthlyConsumption` (integer Wh casts,
`recomputeUnits()`, `belongsTo(Device)`). Integer Wh math, float only at the final `/1000` — billing-grade.

**Incremental maintenance** — in `MeterPayloadProcessor`, inside the *existing* ingestion transaction, in the
same `if ($latestStateWasUpdated && energy_pzem_wh !== null)` branch that already feeds the monthly table:
add `updateDailyConsumption(...)` alongside `updateMonthlyConsumption(...)`. Same `lockForUpdate` upsert, same
reset/rollover handling, day baseline = prior day's `last_energy_wh`. ~1 extra indexed query per promoted
reading, atomic with the reading. Single `flock` consumer ⇒ single writer ⇒ no race.

**Backfill** — `app/Console/Commands/BackfillDailyConsumption.php` (`meters:backfill-daily-consumption`),
copy the proven `BackfillMonthlyConsumption` walk at day granularity. Idempotent. Run once after migrating.

**Finalizer** — `app/Console/Commands/CloseMeterDay.php` (`meters:close-day`), scheduled daily after midnight
(`->dailyAt('00:10')->withoutOverlapping()`), mirroring `CloseMeterMonth`. Safety net for devices silent
across a day boundary.

---

## Deliverable 1 — Range Units KPI

**Endpoint** — `DeviceReadingController::consumption()`:
- Authorize via `DevicePolicy` (see Security) — `$this->authorize('view', $device)`.
- Reuse the existing **`resolveWindow($request)`** (presets + custom `from/to`, returns `null` → 422).
- Delegate to `RangeConsumption::unitsForWindow(...)`. Return `{ units_kwh, reading_count, from, to }`.

**Route** — `routes/api.php`, registered **above** `/devices/{device}/readings` (same reason `chart` is first:
so "consumption" isn't bound as `{device}`). Add `throttle:` middleware.

**Frontend** — `resources/views/devices/dashboards/meter.blade.php`:
- New KPI card in `.kpi-grid` next to "Monthly Units", `id="kpi-range-units"`, with a small range label.
- Server-seed the initial value in `DeviceDashboardController::showMeter()` (call the service for the 1h boot
  window) so the card renders populated.
- `fetchRangeUnits()` reuses the existing range-param builder + kWh formatter; wire into the existing
  `fullLoad()` (boot + every range button + custom Apply) and `backgroundRefresh()` (moving short windows
  only) load paths. Each fetch has its own `.catch(() => '—')` so a failure never breaks the chart/table.
- Poll optimization: do not re-request large unchanged windows (30d/all) on every 30s tick.

## Deliverable 2 — CSV / JSON Export

**Endpoint** — `DeviceReadingController::export()` + route `/devices/{device}/readings/export` (throttled).
- Same policy authorization + same `resolveWindow()`.
- **Stream, never buffer:** `response()->streamDownload()` over a **lazy DB cursor** of `readingColumns()`;
  `fputcsv` to `php://output`, flush per row. JSON format = **NDJSON (JSON Lines)** for true streaming.
- Consumption **summary line** comes from `RangeConsumption` — not a fresh calculation.
- `?format=csv|json`, default csv.
- **Bound the span/row-count.** Over the cap → 422 (or queue an async job emailing a link). Prevents the
  `from=1970&to=now` memory-blowup / DoS vector.
- **CSV-injection safe:** prefix any user-controlled cell beginning with `= + - @ \t \r` with `'`. Sanitize
  the download filename.

## Deliverable 3 — Monthly Energy Summary report

- Read-only: pull from `Device::monthlyConsumptions()` (already finalized by `CloseMeterMonth`); no raw scan,
  no N+1.
- Per-month table (period, units_kwh, finalized) beside the existing 12-month bar chart, with an export button
  reusing Deliverable 2's streaming export.

---

## Security (cross-cutting)

- **Centralize authorization in `DevicePolicy::view`** and replace the hand-rolled
  `if (! isAdminOrAbove() && user_id !== id) abort(403)` guards in `chart()`/`index()`/`consumption()`/`export()`
  with `$this->authorize('view', $device)`. DRY, testable, no endpoint can forget the check, and it sets up
  cleanly for the FGAC work in Part 3.
- **Rate limiting** (`throttle:`) on export (expensive + scrapeable) and the consumption endpoint.
- **No IDOR** — route-model binding + policy; a user cannot read another tenant's device by id.
- **CSV injection + filename sanitization** on export (above).
- Optional: audit-log exports (who pulled what range).

---

## Reliability & the reconciliation guard

`tests/Feature/DeviceReadingConsumptionTest.php` (SQLite, matching `MeterMonthlyConsumptionTest` conventions):

- **Reconciliation (the key test):** ingest a month of readings through the *real* `MeterPayloadProcessor`,
  then assert `consumption(monthStart→now)` equals stored `meter_monthly_consumption.units_kwh`, and that a
  multi-day range equals the sum of `meter_daily_consumption` buckets. This fails the instant any consumption
  path drifts.
- **Edge cases:** empty window → 0; single reading → 0; monotonic increase → `(last−first)/1000`; mid-window
  PZEM reset → rollover applied, never negative; null-energy rows ignored; **partial-day edge stitching**
  (range starting/ending mid-day) exact; current-day computed live, not from a finalized bucket.
- **Security:** non-owner → 403; invalid `from/to` → 422; export over the size cap → 422.
- **Export:** CSV-injection cell is neutralized; NDJSON streams valid lines.

---

## Build order (additive, blast radius ≈ 0)

1. `RangeConsumption` service (short-window raw path) **+ reconciliation/edge tests**.
2. `DevicePolicy::view` + swap the hand-rolled guards in the read endpoints.
3. `meter_daily_consumption`: migration → model → `updateDailyConsumption()` hook → `BackfillDailyConsumption`
   (run it) → `CloseMeterDay` (scheduled). Extend `RangeConsumption` to the tiered (daily + monthly) sources.
4. `consumption()` endpoint + route (throttled) → Range Units KPI card/JS.
5. Streamed, bounded, injection-safe `export()` (CSV + NDJSON).
6. Monthly Energy Summary report (reads rollup/monthly, no raw scan).

No change to MQTT parsing, the ingestion transaction's existing behavior, charts, or the monthly tables.

---

## Verification

- `php artisan migrate` + `php artisan meters:backfill-daily-consumption`; inspect `meter_daily_consumption`
  (one row/device/active day, units ≥ 0, past days finalized, current day null).
- `php artisan test --filter=DeviceReadingConsumption` green.
- Hit `/api/devices/{id}/readings/consumption?range=7d` and `?range=this-month`; confirm the second reconciles
  with the monthly table.
- Open a meter dashboard: Range Units card updates on each range button, custom Apply, and the 30s poll.
- Export a range as CSV and as NDJSON; confirm streaming (no memory spike), the summary line matches the KPI,
  and a `=`-leading cell is neutralized. Confirm an over-cap range returns 422.
- Regression: full `php artisan test` suite green.
