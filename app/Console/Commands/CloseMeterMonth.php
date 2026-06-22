<?php

namespace App\Console\Commands;

use App\Models\MeterMonthlyConsumption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finalise any past-month consumption rows that are still open.
 *
 * Why this exists:
 * - A month is normally closed the moment a device's first reading of the next
 *   month arrives (MeterPayloadProcessor finalises the previous row then).
 * - But a device that goes silent across a month boundary never sends that
 *   first reading, so its previous month would stay open indefinitely.
 * - This safety net runs daily and freezes any row whose month has already
 *   ended, guaranteeing monthly reports always read finalised totals.
 *
 * Idempotent: only touches rows where finalized_at is still null.
 */
class CloseMeterMonth extends Command
{
    protected $signature = 'meters:close-month';

    protected $description = 'Finalise meter monthly consumption rows for months that have already ended';

    public function handle(): int
    {
        $currentPeriod = now()->startOfMonth()->toDateString();

        $closed = MeterMonthlyConsumption::whereNull('finalized_at')
            ->whereDate('period_start', '<', $currentPeriod)
            ->update(['finalized_at' => now()]);

        $this->line("Finalised {$closed} monthly consumption row(s).");
        Log::info('Meter monthly consumption rows finalised', [
            'closed' => $closed,
            'before_period' => $currentPeriod,
        ]);

        return self::SUCCESS;
    }
}
