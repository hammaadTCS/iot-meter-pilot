<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\MeterDailyConsumption;
use App\Services\Meters\RangeConsumption;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DeviceDashboardController extends Controller
{
    public function show(Device $device): View
    {
        $user = Auth::user();

        if (!$user->isAdminOrAbove() && $device->user_id !== $user->id) {
            abort(403, 'You do not have access to this device.');
        }

        if (!$device->is_active) {
            return view('devices.dashboards.placeholder', [
                'device' => $device,
                'reason' => 'disabled',
            ]);
        }

        return match ($device->type) {
            'meter'  => $this->showMeter($device),
            default  => view('devices.dashboards.placeholder', compact('device')),
        };
    }

    private function showMeter(Device $device): View
    {
        $device->load('latestState');

        $recentReadings = $device->readings()
            ->latest('ts')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        // Historical monthly consumption for the "Monthly Units" panel.
        //
        // These rows are maintained incrementally during MQTT ingestion (see
        // MeterPayloadProcessor::updateMonthlyConsumption), so this is a single
        // cheap, pre-aggregated query — never a scan of raw readings. We pull the
        // most recent 12 calendar months (newest first); the chart renders them
        // top-to-bottom with the current month on top.
        //
        // Each row is mapped to a flat, presentation-ready shape:
        //   - period_start as a plain 'Y-m-d' string. We format it explicitly
        //     rather than letting the model's `date` cast serialise it, because
        //     that cast emits a UTC datetime (e.g. "2026-05-31T19:00:00Z" for a
        //     non-UTC app timezone), which would corrupt the month label and the
        //     current-month match on the client.
        //   - units_kwh as a float, so the chart gets a clean number regardless
        //     of the driver (SQLite returns a float, MySQL a decimal string).
        $monthlyConsumption = $device->monthlyConsumptions()
            ->orderByDesc('period_start')
            ->limit(12)
            ->get(['period_start', 'units_kwh'])
            ->map(fn ($row) => [
                'period_start' => $row->period_start->format('Y-m-d'),
                'units_kwh'    => (float) $row->units_kwh,
            ]);

        // Seed the Range Units KPI for the dashboard's default range (1h) so the
        // card renders populated on first paint. Reuses the same RangeConsumption
        // service the client polls, so the seed reconciles with the first fetch.
        $rangeUnits = RangeConsumption::unitsForWindow($device->id, now()->subHour(), null);

        // Daily Breakdown report — seed the current month for first paint; the
        // client re-fetches when another month is picked. Reads the pre-aggregated
        // daily rollup (≤31 rows), never raw history.
        $currentMonth = now()->startOfMonth();
        $dailyBreakdown = MeterDailyConsumption::where('device_id', $device->id)
            ->whereDate('period_date', '>=', $currentMonth->toDateString())
            ->whereDate('period_date', '<=', $currentMonth->copy()->endOfMonth()->toDateString())
            ->orderBy('period_date')
            ->get(['period_date', 'units_kwh'])
            ->map(fn ($r) => ['date' => $r->period_date->format('Y-m-d'), 'units_kwh' => (float) $r->units_kwh])
            ->values();

        // Month options for the picker (newest first); always include the current month.
        $reportMonths = $monthlyConsumption
            ->pluck('period_start')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'));
        if (! $reportMonths->contains($currentMonth->format('Y-m'))) {
            $reportMonths->prepend($currentMonth->format('Y-m'));
        }
        $reportMonths = $reportMonths->unique()->values()->map(fn ($ym) => [
            'value' => $ym,
            'label' => Carbon::createFromFormat('Y-m', $ym)->format('F Y'),
        ]);

        // Current month total (matches the Monthly Units KPI).
        $currentMonthTotal = (float) ($monthlyConsumption
            ->firstWhere('period_start', $currentMonth->toDateString())['units_kwh']
            ?? $dailyBreakdown->sum('units_kwh'));

        return view('devices.dashboards.meter', [
            'device'             => $device,
            'rangeUnits'         => $rangeUnits,
            'dailyBreakdown'     => $dailyBreakdown,
            'reportMonths'       => $reportMonths,
            'currentMonth'       => $currentMonth->format('Y-m'),
            'currentMonthTotal'  => $currentMonthTotal,
            'allDevices'         => collect([]), // picker removed; kept for API compat
            'currentSnapshot'    => $device->currentSnapshot(),
            'deviceAvailability' => $device->availabilitySnapshot(),
            'deviceIssue'        => $device->issueSnapshot(),
            'recentReadings'     => $recentReadings,
            'monthlyConsumption' => $monthlyConsumption,
        ]);
    }
}
