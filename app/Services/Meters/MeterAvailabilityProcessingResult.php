<?php

namespace App\Services\Meters;

use App\Models\Device;

class MeterAvailabilityProcessingResult
{
    public function __construct(
        public string $status,
        public ?Device $device = null,
    ) {
    }

    public static function ignoredUnknownTopic(): self
    {
        return new self(status: 'ignored_unknown_topic');
    }

    public static function stored(Device $device): self
    {
        return new self(
            status: 'stored',
            device: $device,
        );
    }

    public function wasStored(): bool
    {
        return $this->status === 'stored';
    }
}
