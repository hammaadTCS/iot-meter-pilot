<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per device per clock hour, holding the energy consumed ("units")
 * that hour plus exact voltage/power accumulators for read-time averages.
 * Maintained incrementally during MQTT ingestion; it is the data source for
 * the simplified consumer dashboard's hourly historical view (consumers never
 * read raw minute-level rows).
 *
 * Identical in shape and lifecycle to MeterDailyConsumption, one granularity
 * up, plus the average accumulators. See the create_meter_hourly_consumption
 * migration and MeterPayloadProcessor::updateHourlyConsumption() for the full
 * lifecycle. Unlike the day/month rollups these rows are pruned after a
 * retention window (meters:prune-hourly-consumption).
 */
class MeterHourlyConsumption extends Model
{
    protected $table = 'meter_hourly_consumption';

    protected $fillable = [
        'device_id',
        'period_start',
        'baseline_energy_wh',
        'last_energy_wh',
        'rollover_wh',
        'units_kwh',
        'voltage_sum',
        'voltage_count',
        'power_sum',
        'power_count',
        'last_reading_id',
        'last_reading_at',
        'finalized_at',
    ];

    protected $casts = [
        'period_start'       => 'datetime',
        // Cumulative Wh counters are whole numbers; cast for clean arithmetic.
        'baseline_energy_wh' => 'integer',
        'last_energy_wh'     => 'integer',
        'rollover_wh'        => 'integer',
        'voltage_count'      => 'integer',
        'power_count'        => 'integer',
        'last_reading_at'    => 'datetime',
        'finalized_at'       => 'datetime',
    ];

    /**
     * Each hourly total belongs to one device.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Recompute the denormalised kWh figure from the raw Wh fields.
     *
     * units = (last − baseline + rollover) / 1000, clamped to >= 0.
     *
     * Kept byte-for-byte identical to MeterDailyConsumption::recomputeUnits()
     * so hourly, daily and monthly figures reconcile.
     */
    public function recomputeUnits(): void
    {
        $baseline = (int) $this->baseline_energy_wh;
        $last     = (int) $this->last_energy_wh;
        $rollover = (int) $this->rollover_wh;

        $consumedWh = max(0, $last - $baseline + $rollover);

        $this->units_kwh = round($consumedWh / 1000, 3);
    }

    /**
     * Mean voltage across the readings folded into this hour, or null when no
     * reading carried a voltage. Computed from the exact sum/count accumulators.
     */
    public function averageVoltage(): ?float
    {
        return $this->voltage_count > 0
            ? round((float) $this->voltage_sum / $this->voltage_count, 1)
            : null;
    }

    /**
     * Mean active power (W) across the readings folded into this hour, or null
     * when no reading carried a power value.
     */
    public function averagePower(): ?float
    {
        return $this->power_count > 0
            ? round((float) $this->power_sum / $this->power_count, 1)
            : null;
    }
}
