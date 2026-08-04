<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeterAvailabilityUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Device $device,
    ) {
    }

    /**
     * Same per-device private channel as MeterReadingUpdated — availability
     * reveals when a home is online, so it gets the same protection.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("device.{$this->device->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'meter.availability.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'device_id' => $this->device->id,
            'device_code' => $this->device->code,
            'device_name' => $this->device->name,
            'availability' => $this->device->availabilitySnapshot(),
            'health' => $this->device->healthSnapshot(),
        ];
    }
}
