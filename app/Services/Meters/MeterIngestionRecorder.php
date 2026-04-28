<?php

namespace App\Services\Meters;

use App\Models\Device;
use App\Models\MeterIngestionEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class MeterIngestionRecorder
{
    /**
     * Persist a compact operational record for an MQTT ingestion decision.
     * Recording must never break live ingestion, so failures are logged only.
     */
    public function record(
        string $topic,
        string $status,
        ?Device $device = null,
        ?Carbon $receivedAt = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?string $payloadPreview = null,
        array $context = [],
    ): void {
        try {
            MeterIngestionEvent::create([
                'device_id' => $device?->id,
                'topic' => trim($topic),
                'status' => $status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'payload_preview' => $payloadPreview,
                'context' => $context === [] ? null : $context,
                'received_at' => $receivedAt ?? now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Meter ingestion event could not be recorded', [
                'topic' => $topic,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep payload previews useful without letting audit rows become huge.
     */
    public function preview(string $payload, int $limit = 500): string
    {
        return substr(trim($payload), 0, $limit);
    }
}
