<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store runtime payload issue state separately from freshness health.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable()->after('last_seen_at');
            $table->string('last_error_code')->nullable()->after('last_message_at');
            $table->text('last_error_message')->nullable()->after('last_error_code');
            $table->json('last_error_context')->nullable()->after('last_error_message');
            $table->timestamp('last_error_at')->nullable()->after('last_error_context');
            $table->timestamp('last_recovered_at')->nullable()->after('last_error_at');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'last_message_at',
                'last_error_code',
                'last_error_message',
                'last_error_context',
                'last_error_at',
                'last_recovered_at',
            ]);
        });
    }
};
