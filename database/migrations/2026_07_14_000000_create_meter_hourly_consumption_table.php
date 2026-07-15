<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-device, per-clock-hour energy consumption ("units") plus electrical
     * averages — the data source for the SIMPLIFIED consumer dashboard.
     *
     * Why this exists (product decision 2026-07-14):
     * - Consumer-bundle users get a simplified dashboard whose historical view
     *   shows hour/day buckets (units + avg voltage/power), never raw
     *   minute-level rows. The raw `meter_readings` feed stays reserved for the
     *   full operator dashboard (meter.full_dashboard / meter.charts).
     * - Serving hourly buckets from raw readings would be a per-request GROUP BY
     *   scan; at the platform's 10k+ device target that does not scale. This
     *   mirrors the proven `meter_daily_consumption` approach one granularity
     *   up: maintained incrementally during ingestion, read back with an
     *   indexed range query. O(hours), never O(readings).
     *
     * Units follow the exact lifecycle of the daily/monthly rollups
     * (MeterPayloadProcessor::updateHourlyConsumption()):
     *   units_kwh = max(0, (last_energy_wh − baseline_energy_wh + rollover_wh) / 1000)
     * where `baseline_energy_wh` chains from the previous hour's final reading
     * and `rollover_wh` absorbs hardware counter resets.
     *
     * Averages are stored as exact sums + counts (avg computed at read time as
     * sum/count) so day-level averages can be derived from hour rows without
     * loss: avg(day) = Σsum / Σcount. Voltage and power carry separate counts
     * because the payload validator allows either field to be independently
     * absent from a reading.
     *
     * Hour boundaries are clock hours in the app timezone (Asia/Karachi), keyed
     * off `received_at` — consistent with the daily/monthly rollups.
     *
     * Retention: hour rows are only needed for the ≤48h bucket window the
     * simplified dashboard serves plus headroom; meters:prune-hourly-consumption
     * (scheduled daily) trims them. Day/month rollups remain forever.
     */
    public function up(): void
    {
        Schema::create('meter_hourly_consumption', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();

            // Start of the clock hour this row represents (e.g. 2026-07-14 15:00:00).
            $table->dateTime('period_start');

            // Cumulative PZEM counter (Wh) at the start of the hour — i.e. the
            // previous hour's final reading. Null only before the first reading.
            $table->unsignedBigInteger('baseline_energy_wh')->nullable();

            // Most recent cumulative PZEM counter (Wh) observed within the hour.
            $table->unsignedBigInteger('last_energy_wh')->nullable();

            // Sum of pre-reset totals (see meter_daily_consumption for the rule).
            $table->unsignedBigInteger('rollover_wh')->default(0);

            // Denormalised consumption in kWh ("units"). Always >= 0.
            $table->decimal('units_kwh', 14, 3)->default(0);

            // Exact accumulators for read-time averages (avg = sum / count).
            $table->decimal('voltage_sum', 16, 3)->default(0);
            $table->unsignedInteger('voltage_count')->default(0);
            $table->decimal('power_sum', 18, 3)->default(0);
            $table->unsignedInteger('power_count')->default(0);

            // Traceability for the most recent reading folded into this row.
            $table->unsignedBigInteger('last_reading_id')->nullable();
            $table->timestamp('last_reading_at')->nullable();

            // Set once the hour is closed (a later hour started). Kept for
            // symmetry with the day/month rollups; no scheduled closer is needed
            // because an open hour row is still numerically correct.
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();

            // One row per device per hour. Also serves the "previous hour"
            // baseline lookup and the range "buckets between A and B" scan.
            $table->unique(['device_id', 'period_start']);

            // Retention pruning: "every device before hour X".
            $table->index('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_hourly_consumption');
    }
};
