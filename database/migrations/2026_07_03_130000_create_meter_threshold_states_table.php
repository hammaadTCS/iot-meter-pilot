<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hysteresis counters for the electrical threshold checks.
     *
     * One row per (device, check) — e.g. (meter 3, voltage_high) — holding how
     * many consecutive scans the latest reading has been in breach vs back in
     * range. An alert opens only after N consecutive breaching scans and
     * resolves only after N consecutive clear scans, so a single transient
     * spike never opens (or flaps) an alert. Durable (not in-memory) so the
     * debounce survives scheduler/worker restarts.
     *
     * Consumed exclusively by alerts:scan-thresholds.
     */
    public function up(): void
    {
        Schema::create('meter_threshold_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();

            // Which configured check this row tracks:
            // voltage_high | voltage_low | power_max | pf_min
            $table->string('check_key');

            $table->unsignedInteger('breach_streak')->default(0);
            $table->unsignedInteger('clear_streak')->default(0);

            $table->timestamps();

            $table->unique(['device_id', 'check_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_threshold_states');
    }
};
