<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store durable alert lifecycle events for meter health monitoring.
     */
    public function up(): void
    {
        Schema::create('meter_alert_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('alert_type')->index();
            $table->string('severity')->index();
            $table->string('status')->default('open')->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('triggered_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['device_id', 'alert_type', 'status'], 'meter_alert_device_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_alert_events');
    }
};
