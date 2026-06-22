<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-device, per-calendar-month energy consumption ("units").
     *
     * Why this exists:
     * - The PZEM hardware reports a *cumulative* energy counter (Wh) that only
     *   ever climbs. Billing/reporting needs the energy consumed *within* a
     *   month, i.e. (counter at month end − counter at month start).
     * - Computing that on every dashboard read would mean scanning a month of
     *   `meter_readings` rows. Instead we maintain one tiny row per device per
     *   month, updated incrementally during ingestion. Reads become O(1).
     *
     * How a month's units are derived (see MeterPayloadProcessor):
     *   units_kwh = max(0, (last_energy_wh − baseline_energy_wh + rollover_wh) / 1000)
     * where `rollover_wh` absorbs hardware counter resets so the figure can
     * never go backwards. `baseline_energy_wh` is the previous month's final
     * reading, so each month continues exactly where the last one ended.
     *
     * Period boundaries are calendar months in the app timezone (Asia/Karachi),
     * keyed off `received_at` — consistent with how the rest of the system
     * filters time (DeviceReadingController uses COALESCE(received_at, created_at)).
     */
    public function up(): void
    {
        Schema::create('meter_monthly_consumption', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();

            // First day of the month this row represents (e.g. 2026-06-01).
            $table->date('period_start');

            // Cumulative PZEM counter (Wh) at the start of the month — i.e. the
            // previous month's final reading. Null only before the first reading.
            $table->unsignedBigInteger('baseline_energy_wh')->nullable();

            // Most recent cumulative PZEM counter (Wh) observed within the month.
            $table->unsignedBigInteger('last_energy_wh')->nullable();

            // Sum of pre-reset totals. Each time the counter is seen to drop
            // (device/PZEM reset), the value it held just before the drop is
            // added here so consumption keeps accumulating instead of going
            // negative.
            $table->unsignedBigInteger('rollover_wh')->default(0);

            // Denormalised consumption in kWh ("units"). Stored so dashboards and
            // reports never recompute. Always >= 0.
            $table->decimal('units_kwh', 14, 3)->default(0);

            // Traceability for the most recent reading folded into this row.
            $table->unsignedBigInteger('last_reading_id')->nullable();
            $table->timestamp('last_reading_at')->nullable();

            // Set once the month is closed (a later month started, or the
            // safety-net meters:close-month command ran). A finalised row is a
            // frozen value suitable for a monthly report.
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();

            // One row per device per month. Also serves the "previous month"
            // baseline lookup (device_id + period_start = last month).
            $table->unique(['device_id', 'period_start']);

            // Report path: "every device for month X".
            $table->index('period_start');
        });

        /*
         * Cache the current month's units alongside the other latest values so
         * the dashboard snapshot stays a single `latest_meter_states` row read
         * (zero extra queries on the 30s poll). This mirrors the existing role
         * of that table: a fast cache of the most recent reading per device.
         * Additive and nullable, so it cannot disturb existing ingestion.
         */
        Schema::table('latest_meter_states', function (Blueprint $table) {
            $table->decimal('monthly_units_kwh', 14, 3)->nullable()->after('energy_pzem_wh');
        });
    }

    public function down(): void
    {
        Schema::table('latest_meter_states', function (Blueprint $table) {
            $table->dropColumn('monthly_units_kwh');
        });

        Schema::dropIfExists('meter_monthly_consumption');
    }
};
