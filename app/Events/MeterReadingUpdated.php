<?php

namespace App\Events;

use App\Models\Device;
use App\Models\MeterReading;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeterReadingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * We pass the device and the new reading payload to the frontend.
     */
    public function __construct(
        public Device $device,
        public MeterReading $reading,
        public bool $latestStateUpdated = true,
        // Current-month consumption (kWh) after this reading, or null when the
        // reading did not change the monthly aggregate. Optional + last so all
        // existing 2-/3-argument call sites remain valid.
        public ?float $monthlyUnitsKwh = null,
    ) {}

    /**
     * Public channel for this simple pilot.
     * Later you can secure it with private channels and auth.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('meters'),
        ];
    }

    /**
     * Custom event name the frontend will listen for.
     */
    public function broadcastAs(): string
    {
        return 'meter.reading.updated';
    }

    /**
     * Exact payload sent to the browser.
     */
    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'device_code' => $this->device->code,
            'device_name' => $this->device->name,
            'last_seen_at' => $this->device->last_seen_at?->toIso8601String(),
            'latest_state_updated' => $this->latestStateUpdated,
            // Top-level (not inside `reading`) because it is a per-month rollup,
            // not a property of this single sample. The dashboard reads it to
            // refresh the "Monthly Units" KPI card live.
            'monthly_units_kwh' => $this->monthlyUnitsKwh,
            'reading' => [
                'id' => $this->reading->id,
                'ts' => $this->reading->ts,
                'voltage' => $this->reading->voltage,
                'current' => $this->reading->current,
                'power' => $this->reading->power,
                'energy_computed_wh' => $this->reading->energy_computed_wh,
                'energy_pzem_wh' => $this->reading->energy_pzem_wh,
                'frequency' => $this->reading->frequency,
                'pf' => $this->reading->pf,
                'created_at' => $this->reading->created_at?->toIso8601String(),
                'received_at' => $this->reading->received_at?->toIso8601String()
                    ?? $this->device->last_seen_at?->toIso8601String(),
            ],
        ];
    }
}
