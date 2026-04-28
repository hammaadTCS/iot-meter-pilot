<?php

namespace App\Services\Meters;

class MeterPayloadValidator
{
    private const MEASUREMENT_FIELDS = [
        'voltage',
        'current',
        'power',
        'energy_computed_wh',
        'energy_pzem_wh',
        'frequency',
        'pf',
    ];

    /**
     * Validate the minimum payload structure needed for Phase 2A.
     */
    public function validate(array $payload): MeterPayloadValidationResult
    {
        if (!array_key_exists('ts', $payload) || $this->isBlank($payload['ts'])) {
            return MeterPayloadValidationResult::invalid(
                'missing_ts',
                'Payload error: missing required `ts` timestamp.',
            );
        }

        if (!is_numeric($payload['ts']) || (int) $payload['ts'] <= 0) {
            return MeterPayloadValidationResult::invalid(
                'invalid_ts',
                'Payload error: `ts` must be a positive numeric timestamp.',
                ['ts' => $payload['ts']],
            );
        }

        $measurements = [];
        $presentMeasurementFields = [];

        foreach (self::MEASUREMENT_FIELDS as $field) {
            $value = $payload[$field] ?? null;

            if ($this->isBlank($value)) {
                $measurements[$field] = null;
                continue;
            }

            if (!is_numeric($value)) {
                return MeterPayloadValidationResult::invalid(
                    'invalid_numeric_field',
                    "Payload error: `{$field}` must be numeric when present.",
                    [
                        'field' => $field,
                        'value' => $value,
                    ],
                );
            }

            $presentMeasurementFields[] = $field;
            $measurements[$field] = $value;
        }

        if ($presentMeasurementFields === []) {
            return MeterPayloadValidationResult::invalid(
                'missing_measurements',
                'Payload error: no measurement fields were provided.',
            );
        }

        return MeterPayloadValidationResult::valid(
            ts: (int) $payload['ts'],
            measurements: $measurements,
        );
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
