<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-meter, opt-in alert configuration.
     *
     * One row per device holding the user's chosen triggers + thresholds. Every
     * value is nullable / defaulted so "unset" means "that trigger is off" — the
     * detectors skip a meter for any trigger it hasn't configured. This is a
     * fixed, product-defined menu (not a general rule engine), which keeps the UX
     * a simple toggle + number per trigger.
     *
     * Consumed by:
     *   - alerts:scan-consumption  (monthly_budget_*, daily_budget_*, anomaly_*)
     *   - alerts:scan-thresholds   (voltage_*, power_max_kw, pf_min)
     *   - meters:scan-health       (offline_enabled)
     */
    public function up(): void
    {
        Schema::create('meter_alert_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();

            // Consumption
            $table->decimal('monthly_budget_kwh', 14, 3)->nullable();        // null = off
            $table->unsignedTinyInteger('monthly_budget_warn_pct')->default(80);
            $table->decimal('daily_budget_kwh', 14, 3)->nullable();          // null = off

            // Usage anomaly (today vs rolling baseline)
            $table->boolean('anomaly_enabled')->default(false);
            $table->decimal('anomaly_multiplier', 4, 2)->default(2.00);      // today > N x baseline

            // Electrical thresholds (null = off)
            $table->decimal('voltage_high', 8, 2)->nullable();
            $table->decimal('voltage_low', 8, 2)->nullable();
            $table->decimal('power_max_kw', 8, 2)->nullable();
            $table->decimal('pf_min', 3, 2)->nullable();

            // Availability (health) — on by default; gates whether offline alerts fire.
            $table->boolean('offline_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_alert_settings');
    }
};
