<?php

namespace App\Services\Meters;

class MeterPayloadValidationResult
{
    public function __construct(
        public bool $isValid,
        public ?int $ts = null,
        public array $measurements = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $errorContext = [],
    ) {
    }

    public static function valid(int $ts, array $measurements): self
    {
        return new self(
            isValid: true,
            ts: $ts,
            measurements: $measurements,
        );
    }

    public static function invalid(
        string $errorCode,
        string $errorMessage,
        array $errorContext = [],
    ): self {
        return new self(
            isValid: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            errorContext: $errorContext,
        );
    }
}
