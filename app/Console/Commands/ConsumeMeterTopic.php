<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

use App\Models\Device;
use App\Models\MeterReading;
use App\Models\LatestMeterState;
use App\Events\MeterReadingUpdated;

class ConsumeMeterTopic extends Command
{
    protected $signature = 'mqtt:consume-meter';
    protected $description = 'Consume meter MQTT topics and store readings';

    public function handle()
    {
        $this->info('Starting MQTT meter consumer...');

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
                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Subscribe to all device topics
            |--------------------------------------------------------------------------
            */

            foreach ($devices as $device) {

                $topic = trim($device->mqtt_topic);

                $this->info("Subscribing to topic: {$topic}");

                $mqtt->subscribe($topic, function (string $topic, string $message) {

                    try {

                        /*
                        |--------------------------------------------------------------------------
                        | Normalize topic
                        |--------------------------------------------------------------------------
                        */

                        $topic = trim($topic);

                        echo "\n=============================\n";
                        echo "Topic: " . $topic . "\n";
                        echo "Payload: " . $message . "\n";

                        /*
                        |--------------------------------------------------------------------------
                        | Decode JSON
                        |--------------------------------------------------------------------------
                        */

                        $payload = json_decode($message, true);

                        if (!$payload) {
                            echo "Invalid JSON\n";
                            return;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | Find device using topic
                        |--------------------------------------------------------------------------
                        */

                        $device = Device::whereRaw('TRIM(mqtt_topic) = ?', [$topic])->first();

                        if (!$device) {
                            echo "Device NOT FOUND for topic\n";
                            return;
                        }

                        echo "Device matched: ID=" . $device->id . "\n";

                        /*
                        |--------------------------------------------------------------------------
                        | Extract values
                        |--------------------------------------------------------------------------
                        */

                        $ts = $payload['ts'] ?? time();
                        $voltage = $payload['voltage'] ?? null;
                        $current = $payload['current'] ?? null;
                        $power = $payload['power'] ?? null;
                        $energyComputed = $payload['energy_computed_wh'] ?? null;
                        $energyPzem = $payload['energy_pzem_wh'] ?? null;
                        $frequency = $payload['frequency'] ?? null;
                        $pf = $payload['pf'] ?? null;

                        echo "Saving reading...\n";

                        /*
                        |--------------------------------------------------------------------------
                        | Save reading
                        |--------------------------------------------------------------------------
                        */

                        $storedReading = DB::transaction(function () use (
                            $device,
                            $ts,
                            $voltage,
                            $current,
                            $power,
                            $energyComputed,
                            $energyPzem,
                            $frequency,
                            $pf,
                            $payload
                        ) {
                            $receivedAt = now();

                            $reading = MeterReading::updateOrCreate(
                                [
                                    'device_id' => $device->id,
                                    'ts' => $ts,
                                ],
                                [
                                    'voltage' => $voltage,
                                    'current' => $current,
                                    'power' => $power,
                                    'energy_computed_wh' => $energyComputed,
                                    'energy_pzem_wh' => $energyPzem,
                                    'frequency' => $frequency,
                                    'pf' => $pf,
                                    'raw_payload' => $payload,
                                ]
                            );

                            $device->forceFill([
                                'last_seen_at' => $receivedAt,
                            ])->save();

                            /*
                            |--------------------------------------------------------------------------
                            | Update latest state
                            |--------------------------------------------------------------------------
                            */

                            LatestMeterState::updateOrCreate(
                                ['device_id' => $device->id],
                                [
                                    'ts' => $ts,
                                    'voltage' => $voltage,
                                    'current' => $current,
                                    'power' => $power,
                                    'energy_computed_wh' => $energyComputed,
                                    'energy_pzem_wh' => $energyPzem,
                                    'frequency' => $frequency,
                                    'pf' => $pf,
                                    'received_at' => $receivedAt,
                                ]
                            );

                            return $reading->fresh();
                        });

                        echo "Reading stored successfully\n";

                        /*
                        |--------------------------------------------------------------------------
                        | Broadcast realtime event
                        |--------------------------------------------------------------------------
                        */

                        event(new MeterReadingUpdated($device->fresh(), $storedReading));
                    } catch (\Throwable $e) {

                        echo "ERROR: " . $e->getMessage() . "\n";

                        Log::error('MQTT message processing failed', [
                            'topic' => $topic,
                            'error' => $e->getMessage()
                        ]);
                    }

                }, 0);
            }

            /*
            |--------------------------------------------------------------------------
            | Start MQTT loop
            |--------------------------------------------------------------------------
            */

            $mqtt->loop(true);

        } catch (\Throwable $e) {

            $this->error($e->getMessage());

            Log::error('MQTT consumer crashed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
// namespace App\Console\Commands;

// use App\Events\MeterReadingUpdated;
// use App\Models\Device;
// use App\Models\LatestMeterState;
// use App\Models\MeterReading;
// use Illuminate\Console\Command;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use PhpMqtt\Client\Facades\MQTT;
// use Throwable;

// class ConsumeMeterTopic extends Command
// {
//     protected $signature = 'mqtt:consume-meter';

//     protected $description = 'Subscribe to all meter MQTT topics and store readings';

//     public function handle()
//     {
//         $this->info('Starting MQTT meter consumer...');

//         try {

//             $mqtt = MQTT::connection();

//             /*
//              |------------------------------------------
//              | Load all active device topics
//              |------------------------------------------
//              */

//             $devices = Device::where('is_active', true)->get();

//             if ($devices->isEmpty()) {
//                 $this->warn('No active devices found.');
//                 return;
//             }

//             foreach ($devices as $device) {

//                 $topic = $device->mqtt_topic;

//                 $this->info("Subscribing to topic: {$topic}");
//             //     $mqtt->subscribe('#', function (string $topic, string $message) {

//             //     echo "Topic: " . $topic . PHP_EOL;
//             //     echo "Payload: " . $message . PHP_EOL;

//             // });

//                 $mqtt->subscribe($topic, function (string $topic, string $message) {
//                         $topic = trim($topic);
//                      echo "Message received on topic: " . $topic . PHP_EOL;
//                      echo "Payload: " . $message . PHP_EOL;


//                     try {

//                         $payload = json_decode($message, true);

//                         if (!$payload) {
//                             Log::warning('Invalid JSON payload', [
//                                 'topic' => $topic,
//                                 'message' => $message
//                             ]);
//                             return;
//                         }

//                         /*
//                          |------------------------------------------
//                          | Find device using topic
//                          |------------------------------------------
//                          */

//                         // $device = Device::where('mqtt_topic', $topic)->first();
//                         // $device = Device::whereRaw('TRIM(mqtt_topic) = ?', [$topic])->first();
//                         $device = Device::whereRaw('TRIM(mqtt_topic) = ?', [trim($topic)])->first();

//                         if (!$device) {
//                             echo "❌ Device NOT FOUND for topic: " . $topic . PHP_EOL;
//                             return;
//                         }

//                         echo "✅ Device matched: ID=" . $device->id . " Name=" . $device->name . PHP_EOL;
//                         // if (!$device) {
//                         //     Log::warning('Unknown device topic', [
//                         //         'topic' => $topic
//                         //     ]);
//                         //     return;
//                         // }

//                         /*
//                          |------------------------------------------
//                          | Extract values from payload
//                          |------------------------------------------
//                          */

//                         $ts = $payload['ts'] ?? time();

//                         $voltage = $payload['voltage'] ?? null;
//                         $current = $payload['current'] ?? null;
//                         $power = $payload['power'] ?? null;
//                         $energyComputed = $payload['energy_computed_wh'] ?? null;
//                         $energyPzem = $payload['energy_pzem_wh'] ?? null;
//                         $frequency = $payload['frequency'] ?? null;
//                         $pf = $payload['pf'] ?? null;

//                         DB::transaction(function () use (
//                             $device,
//                             $ts,
//                             $voltage,
//                             $current,
//                             $power,
//                             $energyComputed,
//                             $energyPzem,
//                             $frequency,
//                             $pf,
//                             $payload
//                         ) {

//                             /*
//                              |------------------------------------------
//                              | Store history
//                              |------------------------------------------
//                              */

//                             MeterReading::create([
//                             'device_id' => $device->id,
//                             'ts' => $ts,
//                             'voltage' => $voltage,
//                             'current' => $current,
//                             'power' => $power,
//                             'energy_computed_wh' => $energyComputed,
//                             'energy_pzem_wh' => $energyPzem,
//                             'frequency' => $frequency,
//                             'pf' => $pf,
//                             'raw_payload' => json_encode($payload)
//                             ]);

//                             /*
//                              |------------------------------------------
//                              | Update latest state
//                              |------------------------------------------
//                              */

//                             LatestMeterState::updateOrCreate(
//                                 ['device_id' => $device->id],
//                                 [
//                                     'voltage' => $voltage,
//                                     'current' => $current,
//                                     'power' => $power,
//                                     'energy_computed_wh' => $energyComputed,
//                                     'energy_pzem_wh' => $energyPzem,
//                                     'frequency' => $frequency,
//                                     'pf' => $pf,
//                                     'updated_at' => now()
//                                 ]
//                             );

//                             /*
//                              |------------------------------------------
//                              | Broadcast realtime event
//                              |------------------------------------------
//                              */

//                             event(new MeterReadingUpdated($device->id, [
//                                 'voltage' => $voltage,
//                                 'current' => $current,
//                                 'power' => $power,
//                                 'energy_computed_wh' => $energyComputed,
//                                 'energy_pzem_wh' => $energyPzem,
//                                 'frequency' => $frequency,
//                                 'pf' => $pf
//                                 ]));
//                         });

//                     } catch (Throwable $e) {

//                         Log::error('Error processing MQTT message', [
//                             'topic' => $topic,
//                             'error' => $e->getMessage()
//                         ]);
//                     }

//                 }, 0);
//             }

//             $mqtt->loop(true);

//         } catch (Throwable $e) {

//             Log::error('MQTT consumer crashed', [
//                 'error' => $e->getMessage()
//             ]);

//             $this->error($e->getMessage());
//         }
//     }
// }
// namespace App\Console\Commands;

// use App\Events\MeterReadingUpdated;
// use App\Models\Device;
// use App\Models\LatestMeterState;
// use App\Models\MeterReading;
// use Illuminate\Console\Command;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use PhpMqtt\Client\Facades\MQTT;
// use Throwable;

// class ConsumeMeterTopic extends Command
// {
//     protected $signature = 'mqtt:consume-meter';
//     protected $description = 'Subscribe to one meter MQTT topic and store readings in real time';

//     public function handle(): int
//     {
//         $topic = env('METER_TOPIC');

//         if (!$topic) {
//             $this->error('METER_TOPIC is missing in .env');
//             return self::FAILURE;
//         }

//         $device = Device::where('mqtt_topic', $topic)->first();

//         if (!$device) {
//             $this->error("No device found for topic: {$topic}");
//             return self::FAILURE;
//         }

//         $this->info('MQTT runtime configuration:');
//         $this->line('Host: ' . config('mqtt-client.connections.default.host'));
//         $this->line('Port: ' . config('mqtt-client.connections.default.port'));
//         $this->line('Client ID: ' . config('mqtt-client.connections.default.client_id'));
//         $this->line('Topic: ' . $topic);

//         $this->info("Connecting to MQTT broker...");
//         $this->info("Subscribing to topic: {$topic}");

//         $mqtt = MQTT::connection();

//         $mqtt->subscribe($topic, function (string $topic, string $message) use ($device) {
//             $this->line("Message received on topic: {$topic}");
//             $this->line("Raw message: {$message}");

//             Log::info('Raw MQTT message received', [
//                 'topic' => $topic,
//                 'message' => $message,
//             ]);

//             try {
//                 $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

//                 $payload = array_merge([
//                     'ts' => null,
//                     'voltage' => null,
//                     'current' => null,
//                     'power' => null,
//                     'energy_computed_wh' => null,
//                     'energy_pzem_wh' => null,
//                     'frequency' => null,
//                     'pf' => null,
//                 ], $payload);

//                 if ($payload['ts'] === null) {
//                     throw new \RuntimeException('Missing required field: ts');
//                 }

//                 DB::transaction(function () use ($device, $payload) {
//                     MeterReading::updateOrCreate(
//                         [
//                             'device_id' => $device->id,
//                             'ts' => (int) $payload['ts'],
//                         ],
//                         [
//                             'voltage' => is_numeric($payload['voltage']) ? (float) $payload['voltage'] : null,
//                             'current' => is_numeric($payload['current']) ? (float) $payload['current'] : null,
//                             'power' => is_numeric($payload['power']) ? (float) $payload['power'] : null,
//                             'energy_computed_wh' => is_numeric($payload['energy_computed_wh']) ? (float) $payload['energy_computed_wh'] : null,
//                             'energy_pzem_wh' => is_numeric($payload['energy_pzem_wh']) ? (int) $payload['energy_pzem_wh'] : null,
//                             'frequency' => is_numeric($payload['frequency']) ? (float) $payload['frequency'] : null,
//                             'pf' => is_numeric($payload['pf']) ? (float) $payload['pf'] : null,
//                             'raw_payload' => $payload,
//                         ]
//                     );

//                     LatestMeterState::updateOrCreate(
//                         ['device_id' => $device->id],
//                         [
//                             'ts' => (int) $payload['ts'],
//                             'voltage' => is_numeric($payload['voltage']) ? (float) $payload['voltage'] : null,
//                             'current' => is_numeric($payload['current']) ? (float) $payload['current'] : null,
//                             'power' => is_numeric($payload['power']) ? (float) $payload['power'] : null,
//                             'energy_computed_wh' => is_numeric($payload['energy_computed_wh']) ? (float) $payload['energy_computed_wh'] : null,
//                             'energy_pzem_wh' => is_numeric($payload['energy_pzem_wh']) ? (int) $payload['energy_pzem_wh'] : null,
//                             'frequency' => is_numeric($payload['frequency']) ? (float) $payload['frequency'] : null,
//                             'pf' => is_numeric($payload['pf']) ? (float) $payload['pf'] : null,
//                             'received_at' => now(),
//                         ]
//                     );

//                     $device->update([
//                         'last_seen_at' => now(),
//                     ]);
//                 });

//                 event(new MeterReadingUpdated($device, [
//                     'ts' => (int) $payload['ts'],
//                     'voltage' => is_numeric($payload['voltage']) ? (float) $payload['voltage'] : null,
//                     'current' => is_numeric($payload['current']) ? (float) $payload['current'] : null,
//                     'power' => is_numeric($payload['power']) ? (float) $payload['power'] : null,
//                     'energy_computed_wh' => is_numeric($payload['energy_computed_wh']) ? (float) $payload['energy_computed_wh'] : null,
//                     'energy_pzem_wh' => is_numeric($payload['energy_pzem_wh']) ? (int) $payload['energy_pzem_wh'] : null,
//                     'frequency' => is_numeric($payload['frequency']) ? (float) $payload['frequency'] : null,
//                     'pf' => is_numeric($payload['pf']) ? (float) $payload['pf'] : null,
//                     'received_at' => now()->toDateTimeString(),
//                 ]));

//                 $this->info("Saved reading successfully. ts={$payload['ts']}");
//             } catch (Throwable $e) {
//                 Log::error('MQTT meter consume failed', [
//                     'topic' => $topic,
//                     'message' => $message,
//                     'error' => $e->getMessage(),
//                 ]);

//                 $this->error('Failed to process MQTT message: ' . $e->getMessage());
//             }
//         }, 0);

//         $mqtt->loop(true);

//         return self::SUCCESS;
//     }
// }
