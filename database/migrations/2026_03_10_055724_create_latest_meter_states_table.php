<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create latest/current state table.
     *
     * Why this exists:
     * - Dashboard should read current values fast.
     * - We do not want to scan all historical rows just to show "current power".
     */
    public function up(): void
    {
        Schema::create('latest_meter_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('device_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('ts')->nullable();

            $table->decimal('voltage', 8, 2)->nullable();
            $table->decimal('current', 10, 3)->nullable();
            $table->decimal('power', 10, 2)->nullable();
            $table->decimal('energy_computed_wh', 14, 3)->nullable();
            $table->unsignedBigInteger('energy_pzem_wh')->nullable();
            $table->decimal('frequency', 6, 2)->nullable();
            $table->decimal('pf', 5, 2)->nullable();

            // Time when Laravel received/saved this reading.
            $table->timestamp('received_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('latest_meter_states');
    }
};
