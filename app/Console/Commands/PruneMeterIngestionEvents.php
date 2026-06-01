<?php

namespace App\Console\Commands;

use App\Models\MeterIngestionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneMeterIngestionEvents extends Command
{
    protected $signature = 'meters:prune-ingestion-events {--days=30 : Delete events older than this many days}';

    protected $description = 'Delete old meter ingestion audit records to keep the table from growing unbounded';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $deleted = MeterIngestionEvent::where('received_at', '<', $cutoff)->delete();

        $this->line("Pruned {$deleted} ingestion events older than {$days} days.");
        Log::info('Meter ingestion events pruned', ['deleted' => $deleted, 'cutoff' => $cutoff->toIso8601String()]);

        return self::SUCCESS;
    }
}
