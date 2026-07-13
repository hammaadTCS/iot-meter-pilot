<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Device extends Model
{
    /** @use HasFactory<\Database\Factories\DeviceFactory> */
    use HasFactory;

    /**
     * Fields we allow mass assignment on.
     */
    protected $fillable = [
        'user_id',
        'code',
        'name',
        'type',
        'mqtt_topic',
        'availability_topic',
        'is_active',
        'last_seen_at',
        'last_message_at',
        'last_error_code',
        'last_error_message',
        'last_error_context',
        'last_error_at',
        'last_recovered_at',
        'last_availability_status',
        'last_availability_message',
        'last_availability_context',
        'last_availability_at',
        'last_heartbeat_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_message_at' => 'datetime',
        'last_error_context' => 'array',
        'last_error_at' => 'datetime',
        'last_recovered_at' => 'datetime',
        'last_availability_context' => 'array',
        'last_availability_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
    ];

    /**
     * Device belongs to a user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: Only devices for a specific user.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * A device has many historical readings.
     */
    public function readings(): HasMany
    {
        return $this->hasMany(MeterReading::class);
    }

    /**
     * A device has many operational ingestion audit records.
     */
    public function ingestionEvents(): HasMany
    {
        return $this->hasMany(MeterIngestionEvent::class);
    }

    /**
     * A device has many alert lifecycle records.
     */
    public function alertEvents(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * A device has one opt-in alert configuration (budgets, thresholds, etc.).
     */
    public function alertSettings(): HasOne
    {
        return $this->hasOne(MeterAlertSetting::class);
    }

    /**
     * A device has one latest/current state.
     */
    public function latestState(): HasOne
    {
        return $this->hasOne(LatestMeterState::class);
    }

    /**
     * A device has many monthly consumption rollups (one per calendar month).
     * Used by monthly reporting; the *current* month's figure is also cached on
     * latestState for fast dashboard reads.
     */
    public function monthlyConsumptions(): HasMany
    {
        return $this->hasMany(MeterMonthlyConsumption::class);
    }

    /**
     * Build a range-independent current snapshot from the latest state table.
     */
    public function currentSnapshot(): ?array
    {
        $latestState = $this->latestState;

        if (! $latestState) {
            return null;
        }

        return [
            'voltage' => $latestState->voltage,
            'current' => $latestState->current,
            'power' => $latestState->power,
            'energy_computed_wh' => $latestState->energy_computed_wh,
            'energy_pzem_wh' => $latestState->energy_pzem_wh,
            // Current-month consumption (kWh). Read straight from the cached
            // latest state — no extra query on the dashboard's 30s status poll.
            'monthly_units_kwh' => $latestState->monthly_units_kwh,
            'frequency' => $latestState->frequency,
            'pf' => $latestState->pf,
            'recorded_at' => ($latestState->received_at ?? $latestState->updated_at ?? $latestState->created_at)?->toIso8601String(),
        ];
    }

    /**
     * Derive a sensible MQTT availability topic from the data topic.
     */
    public static function deriveAvailabilityTopic(string $mqttTopic): string
    {
        $mqttTopic = trim($mqttTopic);

        if ($mqttTopic === '') {
            return '';
        }

        if (str_ends_with($mqttTopic, '/data')) {
            return substr($mqttTopic, 0, -5).'/status';
        }

        if (str_ends_with($mqttTopic, '/telemetry')) {
            return substr($mqttTopic, 0, -10).'/status';
        }

        if (str_ends_with($mqttTopic, '/status')) {
            return $mqttTopic;
        }

        return rtrim($mqttTopic, '/').'/status';
    }

    /**
     * Return the explicitly configured availability topic, or a derived fallback.
     */
    public function resolvedAvailabilityTopic(): ?string
    {
        $configuredTopic = trim((string) $this->availability_topic);

        if ($configuredTopic !== '') {
            return $configuredTopic;
        }

        $mqttTopic = trim((string) $this->mqtt_topic);

        return $mqttTopic === '' ? null : static::deriveAvailabilityTopic($mqttTopic);
    }

    /**
     * Package the current MQTT availability state into a view-friendly snapshot.
     */
    public function availabilitySnapshot(?Carbon $referenceTime = null): array
    {
        $referenceTime ??= now();
        $status = $this->availabilityStatus($referenceTime);

        return [
            'status' => $status,
            'label' => $this->availabilityLabel($status),
            'message' => $this->availabilityMessage($status, $referenceTime),
            'topic' => $this->resolvedAvailabilityTopic(),
            'raw_status' => $this->last_availability_status,
            'last_message' => $this->last_availability_message,
            'last_availability_at' => $this->last_availability_at?->toIso8601String(),
            'last_heartbeat_at' => $this->last_heartbeat_at?->toIso8601String(),
            'has_signal' => (bool) $this->last_availability_at,
        ];
    }

    /**
     * Classify the current broker/device availability state.
     */
    protected function availabilityStatus(?Carbon $referenceTime = null): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        $referenceTime ??= now();

        if ($this->hasExplicitOfflineAvailabilitySignal()) {
            return 'offline';
        }

        if (
            in_array($this->last_availability_status, ['online', 'heartbeat'], true)
            || $this->offlineSignalWasSuperseded()
        ) {
            return in_array($this->healthStatus($referenceTime), ['stale', 'down'], true)
                ? 'silent'
                : 'online';
        }

        return 'unknown';
    }

    /**
     * Return a short availability label for operator-facing surfaces.
     */
    protected function availabilityLabel(string $status): string
    {
        return match ($status) {
            'disabled' => 'Disabled',
            'offline' => 'Offline',
            'silent' => 'Silent',
            'online' => 'Online',
            default => 'Unknown',
        };
    }

    /**
     * Return a descriptive operator-facing message for availability.
     */
    protected function availabilityMessage(string $status, ?Carbon $referenceTime = null): string
    {
        $referenceTime ??= now();

        return match ($status) {
            'disabled' => 'Monitoring is disabled for this meter.',
            'offline' => ($this->last_availability_message ?: 'MQTT availability reported this meter offline.')
                .' Last availability update '
                .$this->formatRelativeTimestamp($this->last_availability_at)
                .'.',
            'silent' => 'MQTT availability reports this meter online, but telemetry is not arriving. Last valid reading was '
                .$this->formatElapsedSeconds($this->secondsSinceLastSeen($referenceTime) ?? 0)
                .' ago.',
            'online' => $this->last_availability_status === 'heartbeat'
                ? 'MQTT heartbeat confirms this meter is online. Last heartbeat '
                    .$this->formatRelativeTimestamp($this->last_heartbeat_at ?? $this->last_availability_at)
                    .'.'
                : (
                    $this->offlineSignalWasSuperseded()
                        ? 'Telemetry resumed after the last offline availability signal.'
                        : ($this->last_availability_message ?: 'MQTT availability reports this meter online.')
                ),
            default => 'No MQTT availability message has been received for this meter yet.',
        };
    }

    /**
     * Treat an explicit offline status as authoritative until newer telemetry or
     * heartbeat evidence proves the meter came back.
     */
    protected function hasExplicitOfflineAvailabilitySignal(): bool
    {
        if ($this->last_availability_status !== 'offline' || ! $this->last_availability_at) {
            return false;
        }

        return ! $this->offlineSignalWasSuperseded();
    }

    /**
     * A stale offline signal should not remain authoritative once newer
     * telemetry or heartbeat traffic proves the device came back.
     */
    protected function offlineSignalWasSuperseded(): bool
    {
        if ($this->last_availability_status !== 'offline' || ! $this->last_availability_at) {
            return false;
        }

        if ($this->last_heartbeat_at && $this->last_heartbeat_at->greaterThan($this->last_availability_at)) {
            return true;
        }

        return $this->last_seen_at?->greaterThan($this->last_availability_at) ?? false;
    }

    /**
     * Return the current health classification for this device.
     *
     * This is derived from configuration state (`is_active`) and the freshness
     * of the latest telemetry (`last_seen_at`).
     */
    public function healthStatus(?Carbon $referenceTime = null): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if (! $this->last_seen_at) {
            return 'never_seen';
        }

        $secondsSinceLastSeen = $this->secondsSinceLastSeen($referenceTime) ?? 0;
        $thresholds = $this->healthThresholds();

        if ($secondsSinceLastSeen >= $thresholds['down_after_seconds']) {
            return 'down';
        }

        if ($secondsSinceLastSeen >= $thresholds['stale_after_seconds']) {
            return 'stale';
        }

        return 'online';
    }

    /**
     * Return a short, operator-facing label for the current health state.
     */
    public function healthLabel(?Carbon $referenceTime = null): string
    {
        return match ($this->healthStatus($referenceTime)) {
            'disabled' => 'Disabled',
            'never_seen' => 'Never Seen',
            'stale' => 'Stale',
            'down' => 'Down',
            default => 'Online',
        };
    }

    /**
     * Return a more descriptive message suitable for banners and detail rows.
     */
    public function healthMessage(?Carbon $referenceTime = null): string
    {
        $status = $this->healthStatus($referenceTime);
        $secondsSinceLastSeen = $this->secondsSinceLastSeen($referenceTime);

        return match ($status) {
            'disabled' => 'Monitoring is disabled for this meter.',
            'never_seen' => 'No telemetry has been received from this meter yet.',
            'stale' => 'Telemetry is delayed. Last reading was '.$this->formatElapsedSeconds($secondsSinceLastSeen ?? 0).' ago.',
            'down' => 'Meter appears down. No telemetry has been received for '.$this->formatElapsedSeconds($secondsSinceLastSeen ?? 0).'.',
            default => 'Meter is live. Telemetry was received '.$this->formatElapsedSeconds($secondsSinceLastSeen ?? 0).' ago.',
        };
    }

    /**
     * Return the age of the last telemetry update in seconds.
     */
    public function secondsSinceLastSeen(?Carbon $referenceTime = null): ?int
    {
        if (! $this->last_seen_at) {
            return null;
        }

        $referenceTime ??= now();

        return max(0, $referenceTime->getTimestamp() - $this->last_seen_at->getTimestamp());
    }

    /**
     * Package the current health state into a view-friendly snapshot.
     */
    public function healthSnapshot(?Carbon $referenceTime = null): array
    {
        $referenceTime ??= now();
        $thresholds = $this->healthThresholds();

        return [
            'status' => $this->healthStatus($referenceTime),
            'label' => $this->healthLabel($referenceTime),
            'message' => $this->healthMessage($referenceTime),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'seconds_since_last_seen' => $this->secondsSinceLastSeen($referenceTime),
            'stale_after_seconds' => $thresholds['stale_after_seconds'],
            'down_after_seconds' => $thresholds['down_after_seconds'],
            'is_enabled' => (bool) $this->is_active,
        ];
    }

    /**
     * Return true when the latest known payload issue is still unresolved.
     */
    public function hasActiveIssue(): bool
    {
        if (! $this->last_error_code || ! $this->last_error_at) {
            return false;
        }

        if (! $this->last_recovered_at) {
            return true;
        }

        return $this->last_error_at->greaterThan($this->last_recovered_at);
    }

    /**
     * Return a compact operator-facing snapshot of the latest payload issue.
     */
    public function issueSnapshot(?Carbon $referenceTime = null): array
    {
        $referenceTime ??= now();
        $hasActiveIssue = $this->hasActiveIssue();
        $status = $this->issueStatus($hasActiveIssue, $referenceTime);

        return [
            'status' => $status,
            'label' => $this->issueLabel($status),
            'message' => $this->issueMessage($status),
            'has_issue' => $hasActiveIssue,
            'code' => $this->last_error_code,
            'error_message' => $this->last_error_message,
            'context' => $this->last_error_context ?? [],
            'last_error_at' => $this->last_error_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_recovered_at' => $this->last_recovered_at?->toIso8601String(),
        ];
    }

    /**
     * Classify the device's payload issue state.
     */
    protected function issueStatus(bool $hasActiveIssue, ?Carbon $referenceTime = null): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        if ($hasActiveIssue) {
            return 'error';
        }

        if ($this->hasFreshRecovery($referenceTime)) {
            return 'recovered';
        }

        return 'ok';
    }

    /**
     * Only show "Recovered" as a short-lived transition while telemetry is
     * still fresh and the recovery message corresponds to the latest reading.
     */
    protected function hasFreshRecovery(?Carbon $referenceTime = null): bool
    {
        if (! $this->last_recovered_at || ! $this->last_seen_at) {
            return false;
        }

        $referenceTime ??= now();

        if ($this->healthStatus($referenceTime) !== 'online') {
            return false;
        }

        return $this->last_seen_at->getTimestamp() === $this->last_recovered_at->getTimestamp();
    }

    /**
     * Return a short label for the payload issue state.
     */
    protected function issueLabel(string $status): string
    {
        return match ($status) {
            'disabled' => 'Disabled',
            'error' => 'Payload Error',
            'recovered' => 'Recovered',
            default => 'No Issue',
        };
    }

    /**
     * Return a descriptive operator-facing issue message.
     */
    protected function issueMessage(string $status): string
    {
        return match ($status) {
            'disabled' => 'Monitoring is disabled for this meter.',
            'error' => ($this->last_error_message ?: 'The latest MQTT payload could not be processed.')
                .' Last invalid message arrived '
                .$this->formatRelativeTimestamp($this->last_message_at ?? $this->last_error_at)
                .'.',
            'recovered' => 'Valid telemetry resumed '.$this->formatRelativeTimestamp($this->last_recovered_at).'.',
            default => 'No active payload issues.',
        };
    }

    /**
     * Guard the configured thresholds so "down" can never be earlier than "stale".
     */
    protected function healthThresholds(): array
    {
        $staleAfterSeconds = max(60, (int) config('meter-health.stale_after_seconds', 180));
        $downAfterSeconds = max($staleAfterSeconds + 60, (int) config('meter-health.down_after_seconds', 600));

        return [
            'stale_after_seconds' => $staleAfterSeconds,
            'down_after_seconds' => $downAfterSeconds,
        ];
    }

    /**
     * Format an elapsed duration in a compact way for operator-facing copy.
     */
    protected function formatElapsedSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds === 0
                ? $minutes.'m'
                : $minutes.'m '.$remainingSeconds.'s';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours < 24) {
            return $remainingMinutes === 0
                ? $hours.'h'
                : $hours.'h '.$remainingMinutes.'m';
        }

        $days = intdiv($hours, 24);
        $remainingHours = $hours % 24;

        return $remainingHours === 0
            ? $days.'d'
            : $days.'d '.$remainingHours.'h';
    }

    /**
     * Format a timestamp relative to now for operator-facing copy.
     */
    protected function formatRelativeTimestamp(?Carbon $timestamp): string
    {
        if (! $timestamp) {
            return 'at an unknown time';
        }

        return $timestamp->diffForHumans(now(), [
            'syntax' => Carbon::DIFF_RELATIVE_TO_NOW,
            'parts' => 2,
        ]);
    }
}
