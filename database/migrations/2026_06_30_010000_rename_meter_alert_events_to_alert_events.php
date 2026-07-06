<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalise the meter-specific alert table into a device-agnostic one.
     *
     * Alerts are becoming a platform subsystem: every device type (meter today,
     * AC / switch / water later) will produce alerts into one table consumed by a
     * single delivery pipeline. Renaming now — while meters are the only producer
     * — keeps the schema (a one-way-door decision) correct before other types
     * depend on it, so new device types add a detector, not a delivery path.
     *
     *   meter_alert_events  ->  alert_events
     *   + device_type   denormalised so fleet/console filtering needs no join
     *   + notified_at   idempotency guard so delivery never double-sends
     */
    public function up(): void
    {
        Schema::rename('meter_alert_events', 'alert_events');

        Schema::table('alert_events', function (Blueprint $table) {
            $table->string('device_type')->nullable()->after('device_id');
            $table->timestamp('notified_at')->nullable()->after('resolved_at');
            $table->index('device_type');
        });

        // Every existing alert was produced by a meter.
        DB::table('alert_events')->whereNull('device_type')->update(['device_type' => 'meter']);
    }

    public function down(): void
    {
        Schema::table('alert_events', function (Blueprint $table) {
            $table->dropIndex(['device_type']);
            $table->dropColumn(['device_type', 'notified_at']);
        });

        Schema::rename('alert_events', 'meter_alert_events');
    }
};
