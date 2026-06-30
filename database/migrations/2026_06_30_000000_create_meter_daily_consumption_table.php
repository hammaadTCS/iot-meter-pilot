<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-device, per-calendar-day energy consumption ("units").
     *
     * Why this exists (the production scaling decision):
     * - Arbitrary dashboard ranges (7d, 30d, "all", custom) don't align to month
     *   boundaries, so the month rollup can't answer them. Computing them from
     *   raw `meter_readings` on every range change AND every 30s poll, per open
     *   dashboard, does not scale to the platform's 10k+ device target — and raw
     *   readings are pruned at ~90 days, so long-range history can't be rebuilt
     *   from them at all.
     * - A daily bucket bounds every range query: any window resolves to
     *   sum(whole-day buckets) + at most two bounded partial-day edge scans
     *   (each ≤ 24h). O(days), not O(readings).
     *
     * This mirrors `meter_monthly_consumption` exactly, one granularity down, and
     * is maintained the same incremental way during ingestion (see
     * MeterPayloadProcessor::updateDailyConsumption()):
     *   units_kwh = max(0, (last_energy_wh − baseline_energy_wh + rollover_wh) / 1000)
     * where `baseline_energy_wh` is the previous day's final reading, so days
     * chain seamlessly, and `rollover_wh` absorbs hardware counter resets.
     *
     * Day boundaries are calendar days in the app timezone (Asia/Karachi), keyed
     * off `received_at` — consistent with the rest of the system's time filtering
     * (COALESCE(received_at, created_at)).
     */
    public function up(): void
    {
        Schema::create('meter_daily_consumption', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();

            // The calendar day this row represents (e.g. 2026-06-30).
            $table->date('period_date');

            // Cumulative PZEM counter (Wh) at the start of the day — i.e. the
            // previous day's final reading. Null only before the first reading.
            $table->unsignedBigInteger('baseline_energy_wh')->nullable();

            // Most recent cumulative PZEM counter (Wh) observed within the day.
            $table->unsignedBigInteger('last_energy_wh')->nullable();

            // Sum of pre-reset totals. Each time the counter is seen to drop
            // (device/PZEM reset), the value it held just before the drop is added
            // here so consumption keeps accumulating instead of going negative.
            $table->unsignedBigInteger('rollover_wh')->default(0);

            // Denormalised consumption in kWh ("units"). Always >= 0.
            $table->decimal('units_kwh', 14, 3)->default(0);

            // Traceability for the most recent reading folded into this row.
            $table->unsignedBigInteger('last_reading_id')->nullable();
            $table->timestamp('last_reading_at')->nullable();

            // Set once the day is closed (a later day started, or the safety-net
            // meters:close-day command ran). A finalised row is a frozen value.
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();

            // One row per device per day. Also serves the "previous day" baseline
            // lookup and the range "sum buckets between A and B" scan.
            $table->unique(['device_id', 'period_date']);

            // Report/range path: "every device for day X".
            $table->index('period_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_daily_consumption');
    }
};
