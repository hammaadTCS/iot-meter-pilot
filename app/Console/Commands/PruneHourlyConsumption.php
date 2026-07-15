<?php

namespace App\Console\Commands;

use App\Models\MeterHourlyConsumption;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retention for the per-hour consumption rollup.
 *
 * Unlike the day/month rollups (kept forever — they are the billing/report
 * record), hour rows exist only to serve the simplified consumer dashboard's
 * hourly historical view, which the aggregate endpoint uses for windows of
 * ≤ 48 hours. At the platform's 10k+ device target an unpruned hourly table
 * would grow by ~88M rows/year, so rows older than the retention window are
 * dropped daily. Windows older than the retention automatically fall back to
 * day buckets (see DeviceReadingController::aggregate()), so nothing a
 * consumer can request goes missing.
 *
 * Deletes in chunks so the daily run never holds a long write lock.
 */
class PruneHourlyConsumption extends Command
{
    protected $signature = 'meters:prune-hourly-consumption {--days=180 : Keep hour rows newer than this many days}';

    protected $description = 'Delete meter hourly consumption rows older than the retention window';

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')))->startOfHour()->toDateTimeString();

        $total = 0;

        do {
            $deleted = MeterHourlyConsumption::where('period_start', '<', $cutoff)
                ->limit(5000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->line("Pruned {$total} hourly consumption row(s) older than {$cutoff}.");
        Log::info('Meter hourly consumption rows pruned', [
            'pruned' => $total,
            'cutoff' => $cutoff,
        ]);

        return self::SUCCESS;
    }
}
