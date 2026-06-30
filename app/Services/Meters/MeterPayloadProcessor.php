<?php

namespace App\Services\Meters;

use App\Models\Device;
use App\Models\LatestMeterState;
use App\Models\MeterDailyConsumption;
use App\Models\MeterMonthlyConsumption;
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

        [$reading, $latestStateWasUpdated, $monthlyUnitsKwh] = DB::transaction(function () use (
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
            $monthlyUnitsKwh = null;

            if ($latestStateWasUpdated) {
                /*
                 * Fold this reading into the month's consumption aggregate BEFORE
                 * caching the latest state, so the cached `monthly_units_kwh`
                 * already reflects this very reading. Only forward-moving
                 * (promoted) readings reach this branch, and only those carrying a
                 * PZEM energy value contribute — see updateMonthlyConsumption().
                 */
                $energyPzemWh = $validation->measurements['energy_pzem_wh'] ?? null;

                if ($energyPzemWh !== null) {
                    $monthlyUnitsKwh = $this->updateMonthlyConsumption(
                        $device,
                        (int) $energyPzemWh,
                        $receivedAt,
                        (int) $reading->id,
                    );

                    /*
                     * Maintain the per-day aggregate in lockstep with the monthly
                     * one — same transaction, same forward-moving + energy-present
                     * guard. Unlike monthly, the daily figure is not cached on the
                     * latest state (no dashboard KPI reads it directly); it exists
                     * purely as the scalable source for arbitrary range queries
                     * (see RangeConsumption).
                     */
                    $this->updateDailyConsumption(
                        $device,
                        (int) $energyPzemWh,
                        $receivedAt,
                        (int) $reading->id,
                    );
                }

                $latestStateAttributes = array_merge(
                    $validation->measurements,
                    [
                        'ts' => $validation->ts,
                        'received_at' => $receivedAt,
                    ],
                );

                // Only refresh the cached units when we actually recomputed them;
                // a promoted reading without energy must not wipe a good value.
                if ($monthlyUnitsKwh !== null) {
                    $latestStateAttributes['monthly_units_kwh'] = $monthlyUnitsKwh;
                }

                LatestMeterState::updateOrCreate(
                    ['device_id' => $device->id],
                    $latestStateAttributes,
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

            return [$reading->fresh(), $latestStateWasUpdated, $monthlyUnitsKwh];
        });

        return MeterProcessingResult::stored(
            $device->fresh(),
            $reading,
            $latestStateWasUpdated,
            $monthlyUnitsKwh,
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
     * Fold one promoted reading into its calendar month's consumption aggregate
     * and return the resulting kWh figure (so it can be cached on the latest
     * state and broadcast to the dashboard).
     *
     * Runs inside the ingestion transaction and is called only for forward-moving
     * readings that carry a PZEM energy value. The PZEM counter is cumulative, so
     * a month's units are the rise of the counter across that month:
     *
     *   - First reading of a month → continue from the previous month's final
     *     reading (the baseline), so months chain seamlessly. With no prior
     *     month, this reading becomes the baseline and the month starts at zero.
     *     Opening a new month also finalises the previous one for reporting.
     *   - Subsequent readings → advance `last_energy_wh`. If the counter is seen
     *     to drop (PZEM/device reset), the pre-reset total is banked into
     *     `rollover_wh` so consumption never goes backwards.
     *
     * Period equality uses whereDate() so it behaves identically whether the
     * `date` column stores "Y-m-d" (MySQL) or "Y-m-d 00:00:00" (SQLite tests).
     */
    protected function updateMonthlyConsumption(
        Device $device,
        int $energyWh,
        Carbon $receivedAt,
        int $readingId,
    ): float {
        $periodStart = $receivedAt->copy()->startOfMonth()->toDateString();

        $row = MeterMonthlyConsumption::where('device_id', $device->id)
            ->whereDate('period_start', $periodStart)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            $previous = MeterMonthlyConsumption::where('device_id', $device->id)
                ->whereDate('period_start', '<', $periodStart)
                ->orderByDesc('period_start')
                ->first();

            $baseline = $previous?->last_energy_wh ?? $energyWh;

            if ($previous && $previous->finalized_at === null) {
                $previous->forceFill(['finalized_at' => $receivedAt])->save();
            }

            $row = new MeterMonthlyConsumption;
            $row->device_id = $device->id;
            $row->period_start = $periodStart;
            $row->baseline_energy_wh = $baseline;
            $row->last_energy_wh = $energyWh;
            $row->rollover_wh = 0;
        } else {
            if ($energyWh < (int) $row->last_energy_wh) {
                $row->rollover_wh = (int) $row->rollover_wh + (int) $row->last_energy_wh;
            }

            $row->last_energy_wh = $energyWh;
        }

        $row->last_reading_id = $readingId;
        $row->last_reading_at = $receivedAt;
        $row->recomputeUnits();
        $row->save();

        return (float) $row->units_kwh;
    }

    /**
     * Fold one promoted reading into its calendar day's consumption aggregate.
     *
     * The per-day counterpart of updateMonthlyConsumption(), maintained in the
     * same ingestion transaction so daily and monthly figures stay consistent.
     * Identical logic, one granularity down:
     *   - First reading of a day → baseline = previous day's final reading (so
     *     days chain seamlessly); the very first day seeds from its own reading
     *     and starts at zero, and opening a new day finalises the previous one.
     *   - Subsequent readings → advance last_energy_wh; a counter drop
     *     (PZEM/device reset) banks the pre-reset total into rollover_wh.
     *
     * The daily aggregate is the scalable source for arbitrary range queries
     * (RangeConsumption): any window resolves to whole-day buckets plus bounded
     * partial-day edges instead of scanning raw history.
     *
     * Returns the day's units (kWh) for symmetry/testing; the caller does not
     * cache it (no dashboard KPI reads the daily figure directly).
     */
    protected function updateDailyConsumption(
        Device $device,
        int $energyWh,
        Carbon $receivedAt,
        int $readingId,
    ): float {
        $periodDate = $receivedAt->copy()->startOfDay()->toDateString();

        $row = MeterDailyConsumption::where('device_id', $device->id)
            ->whereDate('period_date', $periodDate)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            $previous = MeterDailyConsumption::where('device_id', $device->id)
                ->whereDate('period_date', '<', $periodDate)
                ->orderByDesc('period_date')
                ->first();

            $baseline = $previous?->last_energy_wh ?? $energyWh;

            if ($previous && $previous->finalized_at === null) {
                $previous->forceFill(['finalized_at' => $receivedAt])->save();
            }

            $row = new MeterDailyConsumption;
            $row->device_id = $device->id;
            $row->period_date = $periodDate;
            $row->baseline_energy_wh = $baseline;
            $row->last_energy_wh = $energyWh;
            $row->rollover_wh = 0;
        } else {
            if ($energyWh < (int) $row->last_energy_wh) {
                $row->rollover_wh = (int) $row->rollover_wh + (int) $row->last_energy_wh;
            }

            $row->last_energy_wh = $energyWh;
        }

        $row->last_reading_id = $readingId;
        $row->last_reading_at = $receivedAt;
        $row->recomputeUnits();
        $row->save();

        return (float) $row->units_kwh;
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
