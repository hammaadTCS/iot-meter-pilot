<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The coalescing buffer.
     *
     * When an alert opens/resolves, one row per intended recipient is written
     * here (durable, so a burst survives a worker restart). The scheduled
     * DispatchAlertDigests job then groups undispatched rows per user and sends a
     * SINGLE digest — so a correlated outage (many devices at once) becomes one
     * notification per user instead of thousands. This is what makes delivery
     * safe at scale.
     */
    public function up(): void
    {
        Schema::create('pending_alert_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_event_id')->constrained('alert_events')->cascadeOnDelete();
            $table->string('transition');                 // opened | resolved
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            // The digest flush query: undispatched rows grouped per user.
            $table->index(['user_id', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_alert_notifications');
    }
};
