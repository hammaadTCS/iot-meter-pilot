<?php

namespace App\Console\Commands;

use App\Models\MeterDailyConsumption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finalise any past-day consumption rows that are still open.
 *
 * The daily counterpart of meters:close-month. A day is normally closed the
 * moment a device's first reading of the next day arrives (MeterPayloadProcessor
 * finalises the previous row then). A device that goes silent across a day
 * boundary never sends that reading, so its previous day would otherwise stay
 * open indefinitely. This safety net runs daily and freezes any row whose day
 * has already ended, so range queries and reports always read finalised totals.
 *
 * Idempotent: only touches rows where finalized_at is still null.
 */
class CloseMeterDay extends Command
{
    protected $signature = 'meters:close-day';

    protected $description = 'Finalise meter daily consumption rows for days that have already ended';

    public function handle(): int
    {
        $currentPeriod = now()->startOfDay()->toDateString();

        $closed = MeterDailyConsumption::whereNull('finalized_at')
            ->whereDate('period_date', '<', $currentPeriod)
            ->update(['finalized_at' => now()]);

        $this->line("Finalised {$closed} daily consumption row(s).");
        Log::info('Meter daily consumption rows finalised', [
            'closed' => $closed,
            'before_period' => $currentPeriod,
        ]);

        return self::SUCCESS;
    }
}
