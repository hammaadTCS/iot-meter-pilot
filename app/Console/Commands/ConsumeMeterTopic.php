<?php

namespace App\Console\Commands;

use App\Events\MeterAvailabilityUpdated;
use App\Events\MeterReadingUpdated;
use App\Models\Device;
use App\Services\Meters\MeterAvailabilityProcessor;
use App\Services\Meters\MeterIngestionRecorder;
use App\Services\Meters\MeterPayloadProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class ConsumeMeterTopic extends Command
{
    protected $signature = 'mqtt:consume-meter
                            {--restart-after=50000 : Exit cleanly after this many processed messages so the supervisor recycles memory}';

    protected $description = 'Consume meter MQTT topics and store readings';

    private mixed $consumerLockHandle = null;

    /** @var bool Set to true by SIGTERM/SIGINT so the loop exits after the current message. */
    private bool $shouldStop = false;

    /** Readings older than this many seconds at time of receipt are considered catch-up and skip the live broadcast. */
    private const CATCHUP_THRESHOLD_SECONDS = 120;

    public function handle(
        MeterPayloadProcessor $processor,
        MeterAvailabilityProcessor $availabilityProcessor,
        MeterIngestionRecorder $ingestionRecorder,
    ) {
        $this->line('Starting MQTT meter consumer (PID '.getmypid().')');

        if (! $this->acquireConsumerLock()) {
            $this->warn('Another MQTT meter consumer is already running. Exiting.');
            Log::warning('MQTT consumer refused to start: another instance holds the lock');

            return self::FAILURE;
        }

        $this->installSignalHandlers();

        $maxMessages = max(1, (int) $this->option('restart-after'));
        $processedMessages = 0;

        $connectionConfig = (array) config('mqtt-client.connections.default', []);
        $subscribeQos = (int) ($connectionConfig['subscribe_qos'] ?? 1);
        $retryConfig = (array) ($connectionConfig['retry'] ?? []);
        $retryBaseDelaySeconds = max(1, (int) ($retryConfig['base_delay_seconds'] ?? 5));
        $retryMaxDelaySeconds = max($retryBaseDelaySeconds, (int) ($retryConfig['max_delay_seconds'] ?? 60));
        $reconnectAttempt = 0;

        try {
            while (! $this->shouldStop) {
                try {
                    $mqtt = MQTT::connection();

                    $devices = Device::where('is_active', '=', true)->get();

                    if ($devices->isEmpty()) {
                        $this->warn('No active devices found. Exiting.');

                        return self::SUCCESS;
                    }

                    foreach ($devices as $device) {
                        $topic = trim($device->mqtt_topic);

                        $this->line("Subscribing to: {$topic}");
                        Log::info('MQTT consumer subscribing', ['topic' => $topic, 'device_id' => $device->id, 'qos' => $subscribeQos]);

                        $mqtt->subscribe(
                            $topic,
                            function (string $topic, string $message) use ($processor, &$processedMessages, $maxMessages) {
                                $processedMessages++;

                                try {
                                    $topic = trim($topic);
                                    $result = $processor->process($topic, $message);

                                    if ($result->status === 'ignored_unknown_topic') {
                                        Log::warning('MQTT message ignored: topic not registered', ['topic' => $topic]);

                                        return;
                                    }

                                    $device = $result->device;
                                    $reading = $result->reading;

                                    if ($result->status === 'payload_issue') {
                                        Log::warning('MQTT payload issue', [
                                            'topic' => $topic,
                                            'device_id' => $device?->id,
                                            'error_code' => $result->errorCode,
                                        ]);

                                        return;
                                    }

                                    if (! $result->wasStored() || ! $device || ! $reading) {
                                        return;
                                    }

                                    Log::debug('MQTT reading stored', [
                                        'device_id' => $device->id,
                                        'ts' => $reading->ts,
                                        'latest_state_updated' => $result->latestStateUpdated,
                                    ]);
                                } catch (Throwable $e) {
                                    Log::error('MQTT message processing failed', ['topic' => $topic, 'error' => $e->getMessage()]);

                                    return;
                                }

                                // Skip live broadcast for catch-up readings delivered by the broker after
                                // a reconnect. They are stored correctly in the DB; the dashboard catches
                                // up by polling. Broadcasting all of them would flood Reverb.
                                $isLiveReading = isset($reading) && $reading->ts >= now()->subSeconds(self::CATCHUP_THRESHOLD_SECONDS)->timestamp;

                                if (! $isLiveReading) {
                                    return;
                                }

                                try {
                                    event(new MeterReadingUpdated($device->fresh(), $reading, $result->latestStateUpdated));
                                } catch (Throwable $e) {
                                    Log::warning('MQTT reading stored but broadcast failed', [
                                        'device_id' => $device->id,
                                        'reading_id' => $reading->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }

                                // After reaching the message ceiling, signal the loop to exit so the
                                // supervisor restarts the process with a clean PHP heap.
                                if ($processedMessages >= $maxMessages) {
                                    $this->shouldStop = true;
                                    $this->line("Reached {$maxMessages} messages. Exiting for clean restart.");
                                    Log::info('MQTT consumer exiting for scheduled restart', ['messages_processed' => $processedMessages]);
                                }
                            },
                            $subscribeQos,
                        );

                        $availabilityTopic = trim((string) $device->resolvedAvailabilityTopic());

                        if ($availabilityTopic === '' || $availabilityTopic === $topic) {
                            continue;
                        }

                        $this->line("Subscribing to availability: {$availabilityTopic}");
                        Log::info('MQTT consumer subscribing to availability topic', ['topic' => $availabilityTopic, 'device_id' => $device->id]);

                        $mqtt->subscribe(
                            $availabilityTopic,
                            function (string $topic, string $message) use ($availabilityProcessor, $ingestionRecorder) {
                                try {
                                    $topic = trim($topic);
                                    $result = $availabilityProcessor->process($topic, $message);

                                    if ($result->status === 'ignored_unknown_topic') {
                                        Log::warning('MQTT availability message ignored: topic not registered', ['topic' => $topic]);

                                        $ingestionRecorder->record(
                                            topic: $topic,
                                            status: 'availability_unknown_topic',
                                            payloadPreview: $ingestionRecorder->preview($message),
                                        );

                                        return;
                                    }

                                    $device = $result->device;

                                    if (! $result->wasStored() || ! $device) {
                                        return;
                                    }

                                    Log::debug('Availability stored', ['device_id' => $device->id]);
                                } catch (Throwable $e) {
                                    Log::error('MQTT availability processing failed', ['topic' => $topic, 'error' => $e->getMessage()]);

                                    return;
                                }

                                try {
                                    event(new MeterAvailabilityUpdated($device->fresh()));
                                } catch (Throwable $e) {
                                    Log::warning('MQTT availability stored but broadcast failed', [
                                        'device_id' => $device->id,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            },
                            $subscribeQos,
                        );
                    }

                    $reconnectAttempt = 0;
                    $mqtt->loop(true);
                } catch (Throwable $e) {
                    if ($this->shouldStop) {
                        break;
                    }

                    $this->error($e->getMessage());
                    Log::error('MQTT consumer crashed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                } finally {
                    try {
                        MQTT::disconnect();
                    } catch (Throwable $disconnectError) {
                        Log::warning('MQTT consumer disconnect cleanup failed', ['error' => $disconnectError->getMessage()]);
                    }
                }

                if ($this->shouldStop) {
                    break;
                }

                $retryDelaySeconds = $this->nextRetryDelaySeconds(
                    attempt: $reconnectAttempt,
                    baseDelaySeconds: $retryBaseDelaySeconds,
                    maxDelaySeconds: $retryMaxDelaySeconds,
                );
                $reconnectAttempt++;

                $this->warn("MQTT disconnected. Reconnecting in {$retryDelaySeconds}s (attempt {$reconnectAttempt})...");
                Log::warning('MQTT consumer reconnecting', ['attempt' => $reconnectAttempt, 'delay_seconds' => $retryDelaySeconds]);

                // Sleep in 1-second ticks so a SIGTERM can interrupt the wait.
                for ($i = 0; $i < $retryDelaySeconds && ! $this->shouldStop; $i++) {
                    sleep(1);
                    pcntl_signal_dispatch();
                }
            }
        } finally {
            $this->releaseConsumerLock();
        }

        $this->line('MQTT consumer exited cleanly.');
        Log::info('MQTT consumer stopped', ['messages_processed' => $processedMessages]);

        return self::SUCCESS;
    }

    private function installSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            Log::warning('pcntl extension not available: SIGTERM will not be handled gracefully');

            return;
        }

        $stop = function () {
            $this->shouldStop = true;
            Log::info('MQTT consumer received stop signal');
        };

        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
        pcntl_async_signals(true);
    }

    private function nextRetryDelaySeconds(int $attempt, int $baseDelaySeconds, int $maxDelaySeconds): int
    {
        $exponentialDelay = min($maxDelaySeconds, $baseDelaySeconds * (2 ** min($attempt, 6)));

        return min($maxDelaySeconds, $exponentialDelay + random_int(0, min(3, $exponentialDelay)));
    }

    private function acquireConsumerLock(): bool
    {
        $lockPath = storage_path('framework/mqtt-consumer.lock');
        $handle = fopen($lockPath, 'c');

        if (! $handle) {
            Log::error('MQTT consumer lock file could not be opened', ['path' => $lockPath]);

            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            /** @psalm-suppress UnreachableCode */
            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $this->consumerLockHandle = $handle;

        return true;
    }

    private function releaseConsumerLock(): void
    {
        if (! is_resource($this->consumerLockHandle)) {
            return;
        }

        flock($this->consumerLockHandle, LOCK_UN);
        fclose($this->consumerLockHandle);
        $this->consumerLockHandle = null;
    }
}
