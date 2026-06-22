<?php

namespace App\Services\Meters;

use App\Models\Device;
use App\Models\MeterReading;

class MeterProcessingResult
{
    public function __construct(
        public string $status,
        public ?Device $device = null,
        public ?MeterReading $reading = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $latestStateUpdated = false,
        // Current-month consumption (kWh) after this reading was folded in, or
        // null when the reading did not update the monthly aggregate. Lets the
        // consumer broadcast the figure live without a follow-up query.
        public ?float $monthlyUnitsKwh = null,
    ) {}

    public static function ignoredUnknownTopic(): self
    {
        return new self(status: 'ignored_unknown_topic');
    }

    public static function payloadIssue(
        Device $device,
        string $errorCode,
        string $errorMessage,
    ): self {
        return new self(
            status: 'payload_issue',
            device: $device,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public static function stored(
        Device $device,
        MeterReading $reading,
        bool $latestStateUpdated = true,
        ?float $monthlyUnitsKwh = null,
    ): self {
        return new self(
            status: 'stored',
            device: $device,
            reading: $reading,
            latestStateUpdated: $latestStateUpdated,
            monthlyUnitsKwh: $monthlyUnitsKwh,
        );
    }

    public function wasStored(): bool
    {
        return $this->status === 'stored';
    }
}
