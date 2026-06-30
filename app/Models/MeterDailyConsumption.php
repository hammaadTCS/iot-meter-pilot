<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per device per calendar day, holding the energy consumed ("units")
 * that day. Maintained incrementally during MQTT ingestion so the Range Units
 * KPI, exports, and reports can answer arbitrary windows without scanning raw
 * history (any range = sum of day buckets + bounded partial-day edges).
 *
 * Identical in shape and lifecycle to MeterMonthlyConsumption, one granularity
 * down. See the create_meter_daily_consumption migration and
 * MeterPayloadProcessor::updateDailyConsumption() for the full lifecycle.
 */
class MeterDailyConsumption extends Model
{
    protected $table = 'meter_daily_consumption';

    protected $fillable = [
        'device_id',
        'period_date',
        'baseline_energy_wh',
        'last_energy_wh',
        'rollover_wh',
        'units_kwh',
        'last_reading_id',
        'last_reading_at',
        'finalized_at',
    ];

    protected $casts = [
        'period_date'        => 'date',
        // Cumulative Wh counters are whole numbers; cast for clean arithmetic.
        'baseline_energy_wh' => 'integer',
        'last_energy_wh'     => 'integer',
        'rollover_wh'        => 'integer',
        'last_reading_at'    => 'datetime',
        'finalized_at'       => 'datetime',
    ];

    /**
     * Each daily total belongs to one device.
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
     * Kept byte-for-byte identical to MeterMonthlyConsumption::recomputeUnits()
     * so daily and monthly figures reconcile.
     */
    public function recomputeUnits(): void
    {
        $baseline = (int) $this->baseline_energy_wh;
        $last     = (int) $this->last_energy_wh;
        $rollover = (int) $this->rollover_wh;

        $consumedWh = max(0, $last - $baseline + $rollover);

        $this->units_kwh = round($consumedWh / 1000, 3);
    }
}
