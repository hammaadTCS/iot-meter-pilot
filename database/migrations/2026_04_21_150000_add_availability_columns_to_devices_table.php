<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add explicit MQTT availability tracking for Phase 2B.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('availability_topic')->nullable()->after('mqtt_topic');
            $table->index('availability_topic', 'devices_availability_topic_index');
            $table->string('last_availability_status')->nullable()->after('last_recovered_at');
            $table->text('last_availability_message')->nullable()->after('last_availability_status');
            $table->json('last_availability_context')->nullable()->after('last_availability_message');
            $table->timestamp('last_availability_at')->nullable()->after('last_availability_context');
            $table->timestamp('last_heartbeat_at')->nullable()->after('last_availability_at');
        });

        DB::table('devices')
            ->select(['id', 'mqtt_topic'])
            ->orderBy('id')
            ->get()
            ->each(function (object $device): void {
                $mqttTopic = trim((string) $device->mqtt_topic);

                if ($mqttTopic === '') {
                    return;
                }

                DB::table('devices')
                    ->where('id', $device->id)
                    ->update([
                        'availability_topic' => $this->deriveAvailabilityTopic($mqttTopic),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex('devices_availability_topic_index');
            $table->dropColumn([
                'availability_topic',
                'last_availability_status',
                'last_availability_message',
                'last_availability_context',
                'last_availability_at',
                'last_heartbeat_at',
            ]);
        });
    }

    private function deriveAvailabilityTopic(string $mqttTopic): string
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
};
