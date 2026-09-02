# Data retention for `meter_readings` — analysis and recommendation

**Status:** awaiting management approval (2026-09-02)
**Tracked as:** `PENDING_WORK.md` §0 #11 (and **A11a** for `raw_payload`)
**Blocked by:** §0 #10 — the `RangeConsumption` guard must ship first

`meter_readings` is the only unbounded table in the system. Everything else already has
retention that works: ingestion events at 30 days, hourly rollups at 180 days, alerts
pruned daily. This document is the evidence for choosing a window.

---

## 1. Recommendation

| # | Decision | Recommended | Effect at 50 meters |
|---|---|---|---|
| 1 | Detailed reading retention | **90 days** | Settles at 2.76 GB and stops growing |
| 2 | `raw_payload` retention (**A11a**) | **30 days**, then null the column | Reduces that to ~2.3 GB |

**Not urgent.** 36 GB free; roughly four years of headroom at the 50-device plan. And
only **33 days** of readings exist (oldest 2026-07-30), so a 90-day policy **deletes
nothing until late October 2026**.

The case for deciding anyway: it converts an open-ended cost into a fixed one, and
deleting data is far cheaper to reason about before there is much of it.

---

## 2. Measured cost

Measured on the live database, 2026-09-01/02. Not estimated.

| Metric | Value |
|---|---|
| Readings per meter per day | **1,232** (7-day average) |
| `meter_readings` row | **362 bytes** — kept forever today |
| `meter_ingestion_events` row | **519 bytes** — already deleted after 30 days |
| **Total per reading** | **881 bytes** |
| Per meter per day | 1.04 MB across both tables |
| Per meter per year | 155 MB of readings that never expire |

> An earlier version of this analysis counted only `meter_readings` and missed
> `meter_ingestion_events` — which is the **larger** of the two. The figure was
> understated by ~45%. The numbers here include both.

### Cost by retention window

| Meters | 30 days | 3 months | 1 year | Keep forever (added/yr) |
|---|---|---|---|---|
| 4 — today | 0.12 GB | 0.22 GB | 0.68 GB | +0.6 GB |
| 25 | 0.76 GB | 1.38 GB | 4.24 GB | +3.8 GB |
| **50 — 12-month plan** | **1.52 GB** | **2.76 GB** | **8.47 GB** | **+7.6 GB** |
| 300 | 9.10 GB | 16.57 GB | 50.84 GB | +50.8 GB |

The first three columns are totals that **stop growing**. The last grows without limit.

### Why 90 days rather than 30 or 365

- **30 days** is less than one full billing cycle, so a query about last month's bill
  could not be investigated.
- **1 year** costs three times as much as 90 days for detail nobody has requested at that
  age.
- **90 days** aligns with the 30-day ingestion-event window already in production, so the
  two diagnostic sources expire in a comprehensible order.

---

## 3. What survives — traced per endpoint

Every read path was traced before recommending anything.

| Surface | Reads from | Beyond the cutoff |
|---|---|---|
| Dashboard (all of it) | `MeterDailyConsumption` | **Fully intact** |
| Monthly units / KPIs | `meter_monthly_consumption` | **Kept permanently** |
| `dailyConsumption()` report + export | daily + monthly rollups | **Kept permanently** |
| `aggregate()` — day buckets | `meter_daily_consumption` | **Kept permanently** |
| `aggregate()` — hour buckets | `meter_hourly_consumption` | 180 days (existing policy) |
| `index()` — raw readings list | `meter_readings` | Empty |
| `chart()` — minute-level chart | `meter_readings` | Empty |
| `consumption()` — custom range | daily rollups **+ raw edge days** | **See §4 — must be guarded** |

**The rollups are independent tables, not views.** Each stores its own
`baseline_energy_wh` / `last_energy_wh` / `rollover_wh`, written during ingestion.
Consumption is `last - baseline + rollover`, a difference of cumulative counters — so
**deleting raw readings changes no billing or consumption figure**.

The architecture already anticipated this. `DeviceReadingController::aggregate()` contains:

```php
// far back still has its day rollups (kept forever), so fall back.
if ($bucket === 'hour' && $buckets->isEmpty()) {
    $fallback = $this->dayBuckets(...);
    $bucket = 'day';
}
```

Graceful degradation from fine to coarse buckets was designed in from the start.

---

## 4. The one defect that must be fixed first

`RangeConsumption::forWindow()` computes an arbitrary range as **first part-day** (raw
readings) + **interior whole days** (daily rollups) + **last part-day** (raw readings,
anchored by `lastEnergyBefore()`). The part-days need raw data.

```php
if ($firstCount === 0) {
    return self::rawWalk($deviceId, $start, $end, null)[0];
}
```

If the start day has no readings, it falls through to a raw walk of the whole range,
finds nothing for pruned periods, and **returns a figure that is too low with no error**.
This powers `GET /api/devices/{device}/readings/consumption`, which the controller
documents as reconciling with the Monthly Units KPI.

**A silently wrong consumption figure is worse than a missing one.** The guard must
return an explicit "detailed data unavailable before *date*" instead. `PENDING_WORK.md`
§0 #10, and it blocks #11.

## 5. Two further consequences, recorded

- **Rebuild capability is lost beyond the window.** `BackfillHourlyConsumption`,
  `BackfillDailyConsumption` and `BackfillMonthlyConsumption` all rebuild rollups *from*
  `meter_readings`. Past the cutoff the rollups become unverifiable and unrepairable.
- **Charts of raw readings stop at the boundary.** Expected, not a defect.

---

## 6. Partitioning — A11b stays deferred

Monthly partitioning of `meter_readings` was raised again during this analysis, on the
grounds that at 10,000 devices retention would mean deleting ~12.3 million rows daily,
where dropping a partition is near-instant.

**That does not overturn the existing deferral.** `PENDING_WORK.md` already recorded a
stronger objection: **the test suite runs on SQLite, which has no partitioning**, so a
driver-guarded migration would put a schema shape into production that CI never
exercises. MariaDB also requires the partition column to appear in **every** unique key,
so `meter_readings` would need its primary key changed from `id` to `(id, ts)`.

**A11b's triggers are unchanged:** the prune job exceeding ~2 minutes, or the table
passing ~50 M rows. At the 50-device plan with 90-day retention the table holds ~5.5 M
rows — an order of magnitude below the trigger.

---

## 7. Scale context

Measured single-process ingestion ceiling: **~88 messages/second**
(11.4 ms per message — 16 queries, 4.95 ms DB time, 1.52 ms broadcast).

| Devices | Ingestion load | Storage at 90 days |
|---|---|---|
| 50 | 1% | 3 GB |
| 300 | 5% | 17 GB |
| 1,000 | 16% | 55 GB |
| 5,000 | 81% | 276 GB |
| 10,000 | **162% — exceeded** | **552 GB, 1.1 bn rows** |

Redesign is needed between 5,000 and 10,000 devices — split receiving from processing,
and subscribe once with a wildcard rather than twice per device. Both are recorded under
**A19**, whose cheap wins (dropping the redundant `exists()`, batching ingestion-event
writes, moving rollups to a queued job) come first and are worth ~2–4× on their own.

**None of this is needed now.** At 50 devices the system runs at 0.6% of measured
capacity.

---

## Verification when implemented

1. `RangeConsumption` guard ships and is tested **before** any prune is scheduled.
2. Prune command runs in preview mode first; deletion counts agreed before scheduling.
3. Daily and monthly consumption totals verified **unchanged** before and after a prune.
4. Suite stays green.
