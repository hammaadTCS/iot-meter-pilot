<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user notification delivery preferences.
     *
     * One row per user (created lazily; absent rows fall back to defaults in the
     * model). Normalised rather than JSON so the digest dispatcher can query
     * "which operators want critical fleet alerts" cheaply, and so FGAC (Part 3)
     * can reason about it. In-app channels (database/broadcast) default on so the
     * bell always reflects reality; mail is the gated, noisy channel.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->boolean('mail_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);   // in-app bell
            $table->boolean('broadcast_enabled')->default(true);  // realtime bell

            // Minimum severity that triggers *mail* (in-app always shows all).
            $table->string('min_severity')->default('warning');   // warning | critical

            // Optional quiet window — suppresses mail only; in-app still records.
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();

            // Operator opt-in: 'own' = only my devices; 'all' = fleet-wide delivery.
            $table->string('fleet_scope')->default('own');        // own | all

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
