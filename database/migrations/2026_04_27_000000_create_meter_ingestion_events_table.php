<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create an operational audit table for MQTT ingestion decisions.
     */
    public function up(): void
    {
        Schema::create('meter_ingestion_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('topic')->index();
            $table->string('status')->index();
            $table->string('error_code')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->text('payload_preview')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();

            $table->index(['device_id', 'status', 'received_at'], 'meter_ingestion_device_status_received_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_ingestion_events');
    }
};
