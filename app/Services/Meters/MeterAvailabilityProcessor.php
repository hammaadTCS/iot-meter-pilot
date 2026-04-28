<?php

namespace App\Services\Meters;

use App\Models\Device;

class MeterAvailabilityProcessor
{
    /**
     * Process one MQTT availability payload for a matched status topic.
     */
    public function process(string $topic, string $message): MeterAvailabilityProcessingResult
    {
        $topic = trim($topic);

        $device = Device::whereRaw('TRIM(availability_topic) = ?', [$topic])->first();

        if (!$device) {
            return MeterAvailabilityProcessingResult::ignoredUnknownTopic();
        }

        $receivedAt = now();
        $normalized = $this->normalizePayload($message);

        $device->forceFill([
            'last_availability_status' => $normalized['status'],
            'last_availability_message' => $normalized['message'],
            'last_availability_context' => array_merge(
                ['topic' => $topic],
                $normalized['context'],
            ),
            'last_availability_at' => $receivedAt,
            'last_heartbeat_at' => in_array($normalized['status'], ['online', 'heartbeat'], true)
                ? $receivedAt
                : $device->last_heartbeat_at,
        ])->save();

        return MeterAvailabilityProcessingResult::stored($device->fresh());
    }

    /**
     * Accept either plain string payloads or small JSON objects.
     */
    protected function normalizePayload(string $message): array
    {
        $trimmedMessage = trim($message);
        $decoded = json_decode($trimmedMessage, true);

        $statusCandidate = null;
        $messageCandidate = null;
        $context = [];

        if (is_array($decoded)) {
            $statusCandidate = $decoded['status']
                ?? $decoded['state']
                ?? $decoded['availability']
                ?? $decoded['event']
                ?? null;
            $messageCandidate = isset($decoded['message']) && is_string($decoded['message'])
                ? trim($decoded['message'])
                : null;
            $context['payload_type'] = 'json_object';
        } elseif (is_string($decoded)) {
            $statusCandidate = $decoded;
            $context['payload_type'] = 'json_string';
        } else {
            $statusCandidate = $trimmedMessage;
            $context['payload_type'] = 'raw_string';
        }

        $status = $this->normalizeStatus($statusCandidate, $decoded);

        if ($statusCandidate !== null && $statusCandidate !== '') {
            $context['raw_status'] = $statusCandidate;
        }

        if ($trimmedMessage !== '') {
            $context['payload_preview'] = $this->payloadPreview($trimmedMessage);
        }

        return [
            'status' => $status,
            'message' => $messageCandidate ?: $this->defaultMessage($status),
            'context' => $context,
        ];
    }

    /**
     * Map varied payload conventions onto a small operator-facing status set.
     */
    protected function normalizeStatus(mixed $statusCandidate, mixed $decoded): string
    {
        if (is_array($decoded) && (($decoded['heartbeat'] ?? false) === true || ($decoded['alive'] ?? false) === true)) {
            return 'heartbeat';
        }

        if (is_bool($decoded)) {
            return $decoded ? 'online' : 'offline';
        }

        if (!is_string($statusCandidate)) {
            return 'unknown';
        }

        return match (strtolower(trim($statusCandidate))) {
            'online', 'connected', 'up', 'available' => 'online',
            'heartbeat', 'alive', 'ping' => 'heartbeat',
            'offline', 'disconnected', 'down', 'unavailable' => 'offline',
            default => 'unknown',
        };
    }

    /**
     * Keep availability messages short and operator-facing.
     */
    protected function defaultMessage(string $status): string
    {
        return match ($status) {
            'online' => 'MQTT availability reports this meter online.',
            'heartbeat' => 'MQTT heartbeat received from this meter.',
            'offline' => 'MQTT availability reported this meter offline.',
            default => 'MQTT availability payload received, but the status could not be classified.',
        };
    }

    /**
     * Keep only a compact preview of raw availability payloads.
     */
    protected function payloadPreview(string $message): string
    {
        return substr(trim($message), 0, 500);
    }
}
