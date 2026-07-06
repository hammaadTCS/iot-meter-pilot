<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meter's opt-in alert configuration — the triggers and thresholds a user has
 * turned on. Absent rows resolve to defaults (everything off except offline), so
 * a meter without a row simply has no consumption/threshold alerts.
 */
class MeterAlertSetting extends Model
{
    protected $fillable = [
        'device_id',
        'monthly_budget_kwh',
        'monthly_budget_warn_pct',
        'daily_budget_kwh',
        'anomaly_enabled',
        'anomaly_multiplier',
        'voltage_high',
        'voltage_low',
        'power_max_kw',
        'pf_min',
        'offline_enabled',
    ];

    protected $casts = [
        'monthly_budget_kwh'      => 'decimal:3',
        'monthly_budget_warn_pct' => 'integer',
        'daily_budget_kwh'        => 'decimal:3',
        'anomaly_enabled'         => 'boolean',
        'anomaly_multiplier'      => 'decimal:2',
        'voltage_high'            => 'decimal:2',
        'voltage_low'             => 'decimal:2',
        'power_max_kw'            => 'decimal:2',
        'pf_min'                  => 'decimal:2',
        'offline_enabled'         => 'boolean',
    ];

    protected $attributes = [
        'monthly_budget_warn_pct' => 80,
        'anomaly_enabled'         => false,
        'anomaly_multiplier'      => 2.00,
        'offline_enabled'         => true,
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** The persisted settings for a device, or an unsaved defaults object. */
    public static function forDevice(Device $device): self
    {
        return $device->alertSettings ?? tap(new self, fn (self $s) => $s->device_id = $device->id);
    }
}
