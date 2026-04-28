<?php

namespace App\Services\Meters;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MeterPayloadProcessor
{
    public function __construct(
        protected MeterPayloadValidator $validator,
        protected MeterIngestionRecorder $ingestionRecorder,
    ) {}

    /**
     * Process one MQTT payload for a matched meter topic.
     */
    public function process(string $topic, string $message): MeterProcessingResult
    {
        $topic = trim($topic);
        $receivedAt = now();
        $device = Device::whereRaw('TRIM(mqtt_topic) = ?', [$topic])->first();

        if (! $device) {
            $this->ingestionRecorder->record(
                topic: $topic,
                status: 'unknown_topic',
                receivedAt: $receivedAt,
                payloadPreview: $this->payloadPreview($message),
            );

            return MeterProcessingResult::ignoredUnknownTopic();
        }

        $payload = json_decode($message, true);

        if (! is_array($payload)) {
            $this->recordPayloadIssue(
                device: $device,
                receivedAt: $receivedAt,
                errorCode: 'invalid_json',
                errorMessage: 'Payload error: MQTT message is not valid JSON.',
                errorContext: [
                    'topic' => $topic,
                    'payload_preview' => $this->payloadPreview($message),
                ],
            );

            $this->ingestionRecorder->record(
                topic: $topic,
                status: 'invalid_json',
                device: $device,
                receivedAt: $receivedAt,
                errorCode: 'invalid_json',
                errorMessage: 'Payload error: MQTT message is not valid JSON.',
                payloadPreview: $this->payloadPreview($message),
            );

            return MeterProcessingResult::payloadIssue(
                $device->fresh(),
                'invalid_json',
                'Payload error: MQTT message is not valid JSON.',
            );
        }

        $validation = $this->validator->validate($payload);

        if (! $validation->isValid) {
            $this->recordPayloadIssue(
                device: $device,
                receivedAt: $receivedAt,
                errorCode: $validation->errorCode ?? 'payload_invalid',
                errorMessage: $validation->errorMessage ?? 'Payload error: message could not be processed.',
                errorContext: array_merge(
                    ['topic' => $topic],
                    $validation->errorContext,
                ),
            );

            $this->ingestionRecorder->record(
                topic: $topic,
                status: 'payload_invalid',
                device: $device,
                receivedAt: $receivedAt,
                errorCode: $validation->errorCode ?? 'payload_invalid',
                errorMessage: $validation->errorMessage ?? 'Payload error: message could not be processed.',
                payloadPreview: $this->payloadPreview($message),
                context: $validation->errorContext,
            );

            return MeterProcessingResult::payloadIssue(
                $device->fresh(),
                $validation->errorCode ?? 'payload_invalid',
                $validation->errorMessage ?? 'Payload error: message could not be processed.',
            );
        }

        $hadActiveIssue = $device->hasActiveIssue();

        [$reading, $latestStateWasUpdated] = DB::transaction(function () use (
            $device,
            $receivedAt,
            $validation,
            $payload,
            $message,
            $hadActiveIssue,
        ) {
            $readingAlreadyExisted = MeterReading::where('device_id', $device->id)
                ->where('ts', $validation->ts)
                ->exists();

            $reading = MeterReading::updateOrCreate(
                [
                    'device_id' => $device->id,
                    'ts' => $validation->ts,
                ],
                array_merge(
                    $validation->measurements,
                    [
                        'received_at' => $receivedAt,
                        'raw_payload' => $payload,
                    ],
                ),
            );

            $device->forceFill([
                'last_message_at' => $receivedAt,
                'last_seen_at' => $receivedAt,
                'last_recovered_at' => $hadActiveIssue ? $receivedAt : $device->last_recovered_at,
            ])->save();

            /*
             * Historical storage accepts out-of-order payloads because delayed
             * MQTT delivery is normal in the field. The cached latest state is
             * different: it drives KPI cards, so it must only move forward by
             * device sample timestamp.
             */
            $latestState = LatestMeterState::where('device_id', $device->id)
                ->lockForUpdate()
                ->first();

            $latestStateWasUpdated = $this->shouldPromoteToLatestState($latestState, $validation->ts);

            if ($latestStateWasUpdated) {
                LatestMeterState::updateOrCreate(
                    ['device_id' => $device->id],
                    array_merge(
                        $validation->measurements,
                        [
                            'ts' => $validation->ts,
                            'received_at' => $receivedAt,
                        ],
                    ),
                );
            } else {
                Log::notice('Out-of-order MQTT reading stored but not promoted to latest state', [
                    'device_id' => $device->id,
                    'incoming_ts' => $validation->ts,
                    'latest_ts' => $latestState?->ts,
                    'received_at' => $receivedAt->toIso8601String(),
                ]);
            }

            $this->ingestionRecorder->record(
                topic: (string) $device->mqtt_topic,
                status: $readingAlreadyExisted
                    ? 'duplicate'
                    : ($latestStateWasUpdated ? 'stored' : 'out_of_order'),
                device: $device,
                receivedAt: $receivedAt,
                payloadPreview: $this->payloadPreview($message),
                context: [
                    'reading_id' => $reading->id,
                    'ts' => $validation->ts,
                    'latest_state_updated' => $latestStateWasUpdated,
                    'was_duplicate' => $readingAlreadyExisted,
                ],
            );

            return [$reading->fresh(), $latestStateWasUpdated];
        });

        return MeterProcessingResult::stored(
            $device->fresh(),
            $reading,
            $latestStateWasUpdated,
        );
    }

    /**
     * Decide whether an accepted historical reading should drive the current
     * dashboard state. Equal timestamps are allowed so a corrected same-sample
     * payload can refresh the cached latest state without moving time backward.
     */
    protected function shouldPromoteToLatestState(?LatestMeterState $latestState, int $incomingTs): bool
    {
        if (! $latestState || $latestState->ts === null) {
            return true;
        }

        return $incomingTs >= (int) $latestState->ts;
    }

    /**
     * Persist the latest active payload issue without affecting freshness.
     */
    protected function recordPayloadIssue(
        Device $device,
        Carbon $receivedAt,
        string $errorCode,
        string $errorMessage,
        array $errorContext = [],
    ): void {
        $device->forceFill([
            'last_message_at' => $receivedAt,
            'last_error_code' => $errorCode,
            'last_error_message' => $errorMessage,
            'last_error_context' => $errorContext,
            'last_error_at' => $receivedAt,
        ])->save();
    }

    /**
     * Keep only a compact preview of invalid raw payloads in device state.
     */
    protected function payloadPreview(string $message): string
    {
        return substr(trim($message), 0, 500);
    }
}
