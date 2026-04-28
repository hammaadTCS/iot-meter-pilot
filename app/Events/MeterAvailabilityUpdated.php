<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Broadcasting\Channel;
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

    public function broadcastOn(): array
    {
        return [
            new Channel('meters'),
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
