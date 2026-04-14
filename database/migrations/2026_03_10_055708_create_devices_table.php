<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the devices table.
     *
     * Why this exists:
     * - Even for one pilot meter, the system should treat it as a formal device.
     * - This lets you scale later without redesigning everything.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();

            // Human/system-friendly unique code.
            $table->string('code')->unique();

            // Display name shown on dashboard.
            $table->string('name');

            // For this pilot, the type will be "meter".
            $table->string('type')->default('meter');

            // The exact MQTT topic used by the device.
            $table->string('mqtt_topic')->unique();

            // Useful for enabling/disabling later.
            $table->boolean('is_active')->default(true);

            // Updated when the latest message is received.
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
