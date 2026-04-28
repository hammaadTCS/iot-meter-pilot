<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a true ingest timestamp to historical readings.
     *
     * Why this exists:
     * - `ts` is the device-provided sample time.
     * - `received_at` is when Laravel actually accepted the MQTT reading.
     * - Range filters and "what just arrived?" dashboards should follow
     *   `received_at`, not the raw device timestamp.
     */
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable();
            $table->index(['device_id', 'received_at'], 'meter_readings_device_received_at_index');
        });

        // Existing rows predate this column, so we approximate receive time with
        // the most recent write timestamp already stored on the row.
        DB::table('meter_readings')
            ->whereNull('received_at')
            ->update([
                'received_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropIndex('meter_readings_device_received_at_index');
            $table->dropColumn('received_at');
        });
    }
};
