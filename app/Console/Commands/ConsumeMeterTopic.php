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
    protected $signature = 'mqtt:consume-meter';

    protected $description = 'Consume meter MQTT topics and store readings';

    /**
     * Keep the lock file handle open for the lifetime of the command. PHP
     * releases the flock automatically if the process crashes or exits.
     */
    private mixed $consumerLockHandle = null;

    public function handle(
        MeterPayloadProcessor $processor,
        MeterAvailabilityProcessor $availabilityProcessor,
        MeterIngestionRecorder $ingestionRecorder,
    ) {
        $this->info('Starting MQTT meter consumer...');

        if (! $this->acquireConsumerLock()) {
            $this->warn('Another MQTT meter consumer is already running. Exiting.');

            Log::warning('MQTT consumer refused to start because another instance holds the lock');

            return self::FAILURE;
        }

        $connectionConfig = (array) config('mqtt-client.connections.default', []);
        $subscribeQos = (int) ($connectionConfig['subscribe_qos'] ?? 1);
        $retryConfig = (array) ($connectionConfig['retry'] ?? []);
        $retryBaseDelaySeconds = max(1, (int) ($retryConfig['base_delay_seconds'] ?? env('MQTT_RETRY_DELAY', 5)));
        $retryMaxDelaySeconds = max($retryBaseDelaySeconds, (int) ($retryConfig['max_delay_seconds'] ?? env('MQTT_RETRY_MAX_DELAY', 60)));
        $reconnectAttempt = 0;

        try {
            while (true) {
                try {
                    $mqtt = MQTT::connection();

                    /*
                    |--------------------------------------------------------------------------
                    | Load all active devices
                    |--------------------------------------------------------------------------
                    */

                    $devices = Device::where('is_active', true)->get();

                    if ($devices->isEmpty()) {
                        $this->warn('No active devices found.');

                        return self::SUCCESS;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Subscribe to all device topics
                    |--------------------------------------------------------------------------
                    */

                    foreach ($devices as $device) {

                        $topic = trim($device->mqtt_topic);

                        $this->info("Subscribing to topic: {$topic}");

                        Log::info('MQTT consumer subscribing to data topic', [
                            'topic' => $topic,
                            'device_id' => $device->id,
                            'qos' => $subscribeQos,
                        ]);

                        $mqtt->subscribe($topic, function (string $topic, string $message) use ($processor) {
                            try {
                                $topic = trim($topic);

                                echo "\n=============================\n";
                                echo 'Topic: '.$topic."\n";
                                echo 'Payload: '.$message."\n";

                                $result = $processor->process($topic, $message);

                                if ($result->status === 'ignored_unknown_topic') {
                                    echo "Device NOT FOUND for topic\n";

                                    Log::warning('MQTT message ignored because topic is not registered', [
                                        'topic' => $topic,
                                        'payload_size_bytes' => strlen($message),
                                    ]);

                                    return;
                                }

                                $device = $result->device;
                                $reading = $result->reading;

                                if ($device) {
                                    echo 'Device matched: ID='.$device->id."\n";
                                }

                                if ($result->status === 'payload_issue') {
                                    echo ($result->errorMessage ?? 'Payload issue detected.')."\n";

                                    Log::warning('MQTT payload issue recorded for device', [
                                        'topic' => $topic,
                                        'device_id' => $device?->id,
                                        'error_code' => $result->errorCode,
                                        'error_message' => $result->errorMessage,
                                    ]);

                                    return;
                                }

                                if (! $result->wasStored() || ! $device || ! $reading) {
                                    return;
                                }

                                echo "Reading stored successfully\n";

                                if (! $result->latestStateUpdated) {
                                    Log::notice('MQTT reading stored without changing latest meter state', [
                                        'topic' => $topic,
                                        'device_id' => $device->id,
                                        'reading_id' => $reading->id,
                                        'ts' => $reading->ts,
                                    ]);
                                }
                            } catch (Throwable $e) {

                                echo 'ERROR: '.$e->getMessage()."\n";

                                Log::error('MQTT message processing failed', [
                                    'topic' => $topic,
                                    'error' => $e->getMessage(),
                                ]);

                                return;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Broadcast realtime event
                            |--------------------------------------------------------------------------
                            */

                            try {
                                event(new MeterReadingUpdated($device->fresh(), $reading, $result->latestStateUpdated));
                            } catch (Throwable $e) {
                                echo 'BROADCAST WARNING: '.$e->getMessage()."\n";

                                Log::warning('MQTT reading stored but broadcast failed', [
                                    'topic' => $topic,
                                    'device_id' => $device->id,
                                    'reading_id' => $reading->id,
                                    'ts' => $reading->ts,
                                    'error' => $e->getMessage(),
                                ]);
                            }

                        }, $subscribeQos);

                        $availabilityTopic = trim((string) $device->resolvedAvailabilityTopic());

                        if ($availabilityTopic === '' || $availabilityTopic === $topic) {
                            continue;
                        }

                        $this->info("Subscribing to availability topic: {$availabilityTopic}");

                        Log::info('MQTT consumer subscribing to availability topic', [
                            'topic' => $availabilityTopic,
                            'device_id' => $device->id,
                            'qos' => $subscribeQos,
                        ]);

                        $mqtt->subscribe($availabilityTopic, function (string $topic, string $message) use ($availabilityProcessor, $ingestionRecorder) {
                            try {
                                $topic = trim($topic);

                                echo "\n=============================\n";
                                echo 'Availability Topic: '.$topic."\n";
                                echo 'Availability Payload: '.$message."\n";

                                $result = $availabilityProcessor->process($topic, $message);

                                if ($result->status === 'ignored_unknown_topic') {
                                    echo "Device NOT FOUND for availability topic\n";

                                    Log::warning('MQTT availability message ignored because topic is not registered', [
                                        'topic' => $topic,
                                        'payload_size_bytes' => strlen($message),
                                    ]);

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

                                echo 'Availability stored for device: ID='.$device->id."\n";
                            } catch (Throwable $e) {
                                echo 'ERROR: '.$e->getMessage()."\n";

                                Log::error('MQTT availability processing failed', [
                                    'topic' => $topic,
                                    'error' => $e->getMessage(),
                                ]);

                                return;
                            }

                            try {
                                event(new MeterAvailabilityUpdated($device->fresh()));
                            } catch (Throwable $e) {
                                echo 'BROADCAST WARNING: '.$e->getMessage()."\n";

                                Log::warning('MQTT availability stored but broadcast failed', [
                                    'topic' => $topic,
                                    'device_id' => $device->id,
                                    'availability_status' => $device->last_availability_status,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }, $subscribeQos);
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Start MQTT loop
                    |--------------------------------------------------------------------------
                    */

                    $reconnectAttempt = 0;
                    $mqtt->loop(true);
                } catch (Throwable $e) {
                    $this->error($e->getMessage());

                    Log::error('MQTT consumer crashed', [
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    try {
                        MQTT::disconnect();
                    } catch (Throwable $disconnectError) {
                        Log::warning('MQTT consumer disconnect cleanup failed', [
                            'error' => $disconnectError->getMessage(),
                        ]);
                    }
                }

                $retryDelaySeconds = $this->nextRetryDelaySeconds(
                    attempt: $reconnectAttempt,
                    baseDelaySeconds: $retryBaseDelaySeconds,
                    maxDelaySeconds: $retryMaxDelaySeconds,
                );
                $reconnectAttempt++;

                $this->warn("MQTT consumer disconnected. Reconnecting in {$retryDelaySeconds} second(s)...");
                sleep($retryDelaySeconds);
            }
        } finally {
            $this->releaseConsumerLock();
        }

        return self::SUCCESS;
    }

    /**
     * Calculate a bounded exponential reconnect delay with light jitter.
     * The jitter keeps multiple restarted consumers from reconnecting in lockstep
     * after a broker or network outage.
     */
    private function nextRetryDelaySeconds(
        int $attempt,
        int $baseDelaySeconds,
        int $maxDelaySeconds,
    ): int {
        $exponentialDelay = min(
            $maxDelaySeconds,
            $baseDelaySeconds * (2 ** min($attempt, 6)),
        );

        return min(
            $maxDelaySeconds,
            $exponentialDelay + random_int(0, min(3, $exponentialDelay)),
        );
    }

    /**
     * Use a process-level file lock to prevent duplicate local consumers. This
     * avoids cache TTL expiry problems while the MQTT loop blocks for hours.
     */
    private function acquireConsumerLock(): bool
    {
        $lockPath = storage_path('framework/mqtt-consumer.lock');
        $handle = fopen($lockPath, 'c');

        if (! $handle) {
            Log::error('MQTT consumer lock file could not be opened', [
                'path' => $lockPath,
            ]);

            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        $this->consumerLockHandle = $handle;

        return true;
    }

    /**
     * Release the local process lock during graceful exits. Crash exits are also
     * safe because the operating system releases flock handles automatically.
     */
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
