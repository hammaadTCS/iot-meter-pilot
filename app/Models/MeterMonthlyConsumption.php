<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per device per calendar month, holding the energy consumed ("units")
 * that month. Maintained incrementally during MQTT ingestion so dashboards and
 * monthly reports never have to scan raw history.
 *
 * See the create_meter_monthly_consumption migration and
 * MeterPayloadProcessor::updateMonthlyConsumption() for the full lifecycle.
 */
class MeterMonthlyConsumption extends Model
{
    protected $table = 'meter_monthly_consumption';

    protected $fillable = [
        'device_id',
        'period_start',
        'baseline_energy_wh',
        'last_energy_wh',
        'rollover_wh',
        'units_kwh',
        'last_reading_id',
        'last_reading_at',
        'finalized_at',
    ];

    protected $casts = [
        'period_start'       => 'date',
        // Cumulative Wh counters are whole numbers; cast for clean arithmetic.
        'baseline_energy_wh' => 'integer',
        'last_energy_wh'     => 'integer',
        'rollover_wh'        => 'integer',
        'last_reading_at'    => 'datetime',
        'finalized_at'       => 'datetime',
    ];

    /**
     * Each monthly total belongs to one device.
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
     * The clamp is defensive: with correct rollover handling the value is
     * already non-negative, but a baseline carried over from a month that
     * straddled a hardware reset could otherwise dip below zero.
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
