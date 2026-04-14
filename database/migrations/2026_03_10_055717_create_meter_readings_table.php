<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create historical readings table.
     *
     * Why this exists:
     * - Every MQTT payload is stored here as history.
     * - This supports graphs, reports, and proof of continuous ingestion.
     */
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();

            // Device timestamp from the MQTT payload.
            $table->unsignedBigInteger('ts')->index();

            $table->decimal('voltage', 8, 2)->nullable();
            $table->decimal('current', 10, 3)->nullable();
            $table->decimal('power', 10, 2)->nullable();
            $table->decimal('energy_computed_wh', 14, 3)->nullable();
            $table->unsignedBigInteger('energy_pzem_wh')->nullable();
            $table->decimal('frequency', 6, 2)->nullable();
            $table->decimal('pf', 5, 2)->nullable();

            // Store the full raw MQTT payload too.
            $table->json('raw_payload');

            $table->timestamps();

            // Prevent duplicate inserts for the same device timestamp.
            $table->unique(['device_id', 'ts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
