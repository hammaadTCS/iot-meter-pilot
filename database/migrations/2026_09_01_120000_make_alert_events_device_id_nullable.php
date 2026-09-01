<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow alert_events to carry SYSTEM alerts, which belong to no device.
 *
 * The table was already device-agnostic in intent (see the AlertEvent docblock) and
 * the delivery pipeline already copes: EnqueueAlertForDelivery::recipientIds() guards
 * `$alert->device &&` before reading an owner, then routes to fleet operators holding
 * alerts.fleet_scope. A device-less alert therefore skips the customer-owner path and
 * reaches operators — the right audience for "the disk is filling".
 *
 * The NOT NULL constraint was the only thing in the way.
 *
 * Rows created by ScanSystemHealth use device_id = NULL and device_type = 'system'.
 * Device alerts are untouched, and AlertEvent::scopeVisibleTo() already hides
 * system alerts from alerts.view_own users, because whereHas('device') cannot match
 * a null relation.
 */
return new class extends Migration
{
    /**
     * Named explicitly because it predates the meter_alert_events -> alert_events
     * rename and kept the original name.
     */
    private const FOREIGN_KEY = 'meter_alert_events_device_id_foreign';

    public function up(): void
    {
        // SQLite (used by the test suite) rebuilds the table on ->change() and does
        // not support dropping a foreign key by name, so only MySQL/MariaDB needs
        // the drop-change-restore dance.
        $needsForeignKeyDance = DB::connection()->getDriverName() !== 'sqlite';

        if ($needsForeignKeyDance) {
            Schema::table('alert_events', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        Schema::table('alert_events', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->change();
        });

        if ($needsForeignKeyDance) {
            Schema::table('alert_events', function (Blueprint $table) {
                $table->foreign('device_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        // System alerts cannot survive a NOT NULL column. Remove them rather than
        // letting the migration fail half-applied.
        DB::table('alert_events')->whereNull('device_id')->delete();

        $needsForeignKeyDance = DB::connection()->getDriverName() !== 'sqlite';

        if ($needsForeignKeyDance) {
            Schema::table('alert_events', function (Blueprint $table) {
                $table->dropForeign(self::FOREIGN_KEY);
            });
        }

        Schema::table('alert_events', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable(false)->change();
        });

        if ($needsForeignKeyDance) {
            Schema::table('alert_events', function (Blueprint $table) {
                $table->foreign('device_id', self::FOREIGN_KEY)
                    ->references('id')
                    ->on('devices')
                    ->cascadeOnDelete();
            });
        }
    }
};
