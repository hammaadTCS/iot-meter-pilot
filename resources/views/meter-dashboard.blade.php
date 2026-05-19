{{--
|--------------------------------------------------------------------------
| meter-dashboard.blade.php
|--------------------------------------------------------------------------
|
| Real-time electricity meter dashboard for a single device.
|
| Features:
|   - Live KPI cards (voltage, current, power, energy, frequency, PF)
|   - 5 Chart.js charts: Voltage+Current (dual-axis), Power, Energy
|     comparison, Frequency, and Power Factor (colour-coded bar)
|   - Time-range filter: 1H · 6H · 24H · Today · 7 Days
|   - Auto-refresh every 30 seconds — fetches only NEW rows using
|     the "after" cursor (last known ID), so no duplicate data
|   - Table shows newest readings at the top, oldest at the bottom
|   - Charts plot oldest → newest left to right (chronological)
|   - Spinner overlay during first load; silent background refresh
|     for subsequent polls so the UI never flickers
|
| Variables expected from the controller:
|   $devices         — collection of active meter devices for the selector
|   $device          — Device model (id, name, mqtt_topic, last_seen_at)
|   $device->latestState — latest DeviceReading row (all fields)
|   $deviceAvailability — MQTT availability snapshot for the selected meter
|   $deviceIssue     — payload issue snapshot for the selected meter
|   $currentSnapshot — range-independent "current" KPI payload
|   $recentReadings  — NOT used anymore; data loaded entirely via AJAX
|
| API endpoints consumed:
|   GET /api/devices/{id}/readings?range=1h&after=0
|   → Returns JSON array of readings, oldest first
|   GET /api/devices/{id}/status
|   → Returns health, availability, issue, and current snapshot
|   → See DeviceReadingController.php for the backend implementation
|
--}}
@php($deviceHealth = $device->healthSnapshot())
@php($deviceAvailability = $deviceAvailability ?? $device->availabilitySnapshot())
@php($deviceIssue = $deviceIssue ?? $device->issueSnapshot())
@php($currentSnapshotRecordedAt = data_get($currentSnapshot, 'recorded_at'))
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $device->name }} — Meter Pilot</title>

    {{-- Vite-compiled app assets (your existing setup) --}}
    @vite(['resources/js/app.js'])

    {{-- Google Fonts: Space Mono for data/code, DM Sans for UI text --}}
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    {{-- Chart.js v4 for all graphs --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        /* ─────────────────────────────────────────────────────────────
         * CSS CUSTOM PROPERTIES — change colours here, they cascade
         * everywhere automatically
         * ───────────────────────────────────────────────────────────── */
        :root {
            --bg:        #0b0f1a;   /* page background */
            --surface:   #111827;   /* card background */
            --surface2:  #1a2235;   /* table header / hover */
            --border:    #1f2d45;   /* all borders */
            --accent:    #00e5ff;   /* primary cyan accent */
            --accent2:   #7c3aed;   /* purple accent */
            --green:     #10b981;
            --amber:     #f59e0b;
            --red:       #ef4444;
            --text:      #e2e8f0;   /* main body text */
            --muted:     #64748b;   /* labels, captions */
            --font-mono: 'Space Mono', monospace;
            --font-body: 'DM Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Subtle dot-grid background texture */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(0,229,255,.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,229,255,.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Page wrapper ── */
        .shell {
            position: relative;
            z-index: 1;
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 24px 80px;
        }

        /* ─────────────────────────────────────────────────────────────
         * HEADER
         * ───────────────────────────────────────────────────────────── */
        .header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 40px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        .header-left { display: flex; flex-direction: column; gap: 6px; }

        /* "● METER PILOT — LIVE" badge above the device name */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: .12em;
        }

        /* Pulsing green/cyan dot used in badge and live-pill */
        .badge-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 8px var(--accent);
            animation: pulse-dot 2s ease-in-out infinite;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1;  transform: scale(1);   }
            50%       { opacity: .4; transform: scale(.75); }
        }

        h1 {
            font-family: var(--font-mono);
            font-size: clamp(20px, 4vw, 34px);
            font-weight: 700;
            color: #fff;
            letter-spacing: -.01em;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
            color: var(--muted);
        }
        .meta-row > div {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .meta-row span { font-family: var(--font-mono); color: var(--text); }

        .health-pill {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            font: 700 10px/1 var(--font-mono);
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .health-pill--online {
            color: var(--green);
            background: rgba(16,185,129,.12);
            border-color: rgba(16,185,129,.25);
        }

        .health-pill--silent {
            color: #fcd34d;
            background: rgba(245,158,11,.12);
            border-color: rgba(245,158,11,.25);
        }

        .health-pill--offline {
            color: #fca5a5;
            background: rgba(239,68,68,.12);
            border-color: rgba(239,68,68,.25);
        }

        .health-pill--stale {
            color: #fcd34d;
            background: rgba(245,158,11,.12);
            border-color: rgba(245,158,11,.25);
        }

        .health-pill--down {
            color: #fca5a5;
            background: rgba(239,68,68,.12);
            border-color: rgba(239,68,68,.25);
        }

        .health-pill--never_seen,
        .health-pill--disabled {
            color: #cbd5e1;
            background: rgba(148,163,184,.12);
            border-color: rgba(148,163,184,.25);
        }

        /* Top-right pill showing auto-refresh countdown */
        .header-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        /* Device picker keeps the page scoped to one selected meter while the
         * backend continues storing data for all active meters.
         */
        .device-picker {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .device-picker label {
            color: var(--muted);
            font: 700 10px/1 var(--font-mono);
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .device-picker select {
            min-width: 240px;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            color: var(--text);
            font: 400 14px/1.3 var(--font-body);
        }

        .device-picker select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,229,255,.12);
        }

        .live-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,229,255,.07);
            border: 1px solid rgba(0,229,255,.2);
            border-radius: 999px;
            padding: 8px 18px;
            font-size: 12px;
            font-family: var(--font-mono);
            color: var(--accent);
            white-space: nowrap;
        }

        /* Countdown timer shown inside the live pill */
        #refreshCountdown {
            font-weight: 700;
            min-width: 20px;
            text-align: right;
        }

        /* Manual refresh button */
        .refresh-btn {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 6px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: all .15s;
            letter-spacing: .05em;
        }
        .refresh-btn:hover { border-color: var(--accent); color: var(--accent); }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .header-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .status-stack {
            display: grid;
            gap: 12px;
            margin: -12px 0 28px;
        }

        .status-banner {
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid transparent;
            font-size: 14px;
            line-height: 1.5;
        }

        .status-banner.is-hidden {
            display: none;
        }

        .status-banner--online {
            color: #d1fae5;
            background: rgba(16,185,129,.12);
            border-color: rgba(16,185,129,.25);
        }

        .status-banner--silent {
            color: #fde68a;
            background: rgba(245,158,11,.12);
            border-color: rgba(245,158,11,.25);
        }

        .status-banner--offline {
            color: #fecaca;
            background: rgba(239,68,68,.12);
            border-color: rgba(239,68,68,.25);
        }

        .status-banner--ok,
        .status-banner--recovered {
            color: #d1fae5;
            background: rgba(16,185,129,.12);
            border-color: rgba(16,185,129,.25);
        }

        .status-banner--stale {
            color: #fde68a;
            background: rgba(245,158,11,.12);
            border-color: rgba(245,158,11,.25);
        }

        .status-banner--down,
        .status-banner--error {
            color: #fecaca;
            background: rgba(239,68,68,.12);
            border-color: rgba(239,68,68,.25);
        }

        .status-banner--never_seen,
        .status-banner--disabled {
            color: #cbd5e1;
            background: rgba(148,163,184,.12);
            border-color: rgba(148,163,184,.25);
        }

        /* ─────────────────────────────────────────────────────────────
         * KPI CARDS  (always show the most recent reading — unaffected
         * by the time range selector)
         * ───────────────────────────────────────────────────────────── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 14px;
            margin-bottom: 36px;
        }

        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 18px;
            position: relative;
            overflow: hidden;
            transition: border-color .2s, transform .2s;
        }
        /* Accent colour is set via --c on each .kpi--* modifier */
        .kpi:hover { border-color: var(--c, var(--accent)); transform: translateY(-2px); }

        /* Subtle diagonal gradient overlay for depth */
        .kpi::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.025) 0%, transparent 60%);
            pointer-events: none;
        }

        .kpi-icon  { font-size: 18px; margin-bottom: 10px; display: block; }
        .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 6px; }
        .kpi-value { font-family: var(--font-mono); font-size: 26px; font-weight: 700; color: var(--c, #fff); line-height: 1; }
        .kpi-unit  { font-family: var(--font-mono); font-size: 12px; color: var(--muted); margin-top: 4px; }

        /* Individual accent colours per card */
        .kpi--voltage  { --c: #00e5ff; }
        .kpi--current  { --c: #7c3aed; }
        .kpi--power    { --c: #f59e0b; }
        .kpi--energy-c { --c: #10b981; }
        .kpi--energy-p { --c: #3b82f6; }
        .kpi--freq     { --c: #ec4899; }
        .kpi--pf       { --c: #f97316; }
        .kpi--ts       { --c: #94a3b8; }

        /* ─────────────────────────────────────────────────────────────
         * TIME RANGE SELECTOR  — controls both charts AND table
         * ───────────────────────────────────────────────────────────── */
        .controls-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .range-bar {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .range-label {
            font-family: var(--font-mono);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            margin-right: 6px;
        }

        .range-btn {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 6px 16px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: all .15s;
            letter-spacing: .06em;
        }
        .range-btn:hover { border-color: var(--accent); color: var(--accent); }
        .range-btn.active {
            background: rgba(0,229,255,.12);
            border-color: var(--accent);
            color: var(--accent);
            box-shadow: 0 0 12px rgba(0,229,255,.18);
        }

        /* Vertical divider between short ranges and 7-day */
        .range-divider { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }

        /* Small status badge: "12 new rows" after a silent refresh */
        .new-badge {
            font-family: var(--font-mono);
            font-size: 10px;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(16,185,129,.15);
            border: 1px solid rgba(16,185,129,.35);
            color: var(--green);
            opacity: 0;
            transition: opacity .4s;
        }
        .new-badge.show { opacity: 1; }

        /* ─────────────────────────────────────────────────────────────
         * LOADING / SPINNER  — full-section overlay on first load only
         * ───────────────────────────────────────────────────────────── */
        .section-wrap { position: relative; }

        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(11,15,26,.8);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 20;
            border-radius: 16px;
            backdrop-filter: blur(3px);
        }
        .loading-overlay.show { display: flex; }

        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin .65s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ─────────────────────────────────────────────────────────────
         * CHARTS
         * ───────────────────────────────────────────────────────────── */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 36px;
        }
        @media (max-width: 860px) { .charts-grid { grid-template-columns: 1fr; } }

        .chart-wide { grid-column: 1 / -1; }

        .chart-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
        }

        /* Coloured top-border accent line */
        .chart-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            border-radius: 16px 16px 0 0;
        }

        .chart-title {
            font-family: var(--font-mono);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            margin-bottom: 20px;
        }

        /* Fixed height so charts don't collapse or grow uncontrollably */
        .chart-wrap { position: relative; height: 220px; }

        /* ─────────────────────────────────────────────────────────────
         * READINGS TABLE
         * ───────────────────────────────────────────────────────────── */
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 10px;
        }

        .section-title {
            font-family: var(--font-mono);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--muted);
        }

        .table-meta {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--muted);
        }
        .table-meta strong { color: var(--accent); }

        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: auto;        /* horizontal scroll on small screens */
            max-height: 460px;     /* vertical scroll if many rows */
        }

        table { width: 100%; border-collapse: collapse; font-size: 13px; }

        /* Sticky header so column names stay visible while scrolling */
        thead th {
            background: var(--surface2);
            padding: 13px 14px;
            text-align: left;
            font-family: var(--font-mono);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .12s;
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--surface2); }

        tbody td {
            padding: 11px 14px;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text);
            white-space: nowrap;
        }

        /* Timestamp column is dimmer */
        tbody td:first-child { color: var(--muted); }

        /* Very latest row (newest, at top) is highlighted */
        tbody tr:first-child td               { color: #fff; }
        tbody tr:first-child td:first-child   { color: var(--accent); }

        /* "New" row flash animation when a row is prepended by auto-refresh */
        @keyframes row-flash {
            0%   { background: rgba(0,229,255,.18); }
            100% { background: transparent; }
        }
        .row-new { animation: row-flash 1.5s ease-out forwards; }

        .empty-state {
            text-align: center;
            padding: 48px;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--muted);
        }

        @media (max-width: 720px) {
            .header-right,
            .device-picker,
            .header-actions {
                width: 100%;
                align-items: stretch;
                justify-content: stretch;
            }

            .device-picker select,
            .refresh-btn,
            .header-link {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="shell">

    {{-- ══════════════════════════════════════════════════════════
         HEADER
         Device name, MQTT topic, last-seen, refresh countdown
    ══════════════════════════════════════════════════════════ --}}
    <header class="header">
        <div class="header-left">
            <div class="badge">
                <span class="badge-dot"></span>
                Meter Pilot — Live
            </div>

            {{-- Device name from the $device model --}}
            <h1>{{ $device->name }}</h1>

            <div class="meta-row">
                <div>Topic &nbsp;<span>{{ $device->mqtt_topic }}</span></div>
                <div>Last Seen &nbsp;<span id="last_seen">{{ optional($device->last_seen_at)->toDateTimeString() ?? '—' }}</span></div>
                <div>
                    Health
                    <span id="deviceHealthLabel" class="health-pill health-pill--{{ $deviceHealth['status'] }}">
                        {{ $deviceHealth['label'] }}
                    </span>
                </div>
                <div>
                    Availability
                    <span id="deviceAvailabilityLabel" class="health-pill health-pill--{{ $deviceAvailability['status'] }}">
                        {{ $deviceAvailability['label'] }}
                    </span>
                </div>
            </div>
        </div>

        <div class="header-right">
            {{-- Meter selector reloads the page with a scoped device id. --}}
            <form method="GET" action="{{ route('devices.dashboard') }}" class="device-picker">
                <label for="deviceSelect">Meter</label>
                <select id="deviceSelect" name="device" onchange="this.form.submit()">
                    @foreach ($devices as $meter)
                        <option value="{{ $meter->id }}" @selected($device->id === $meter->id)>
                            {{ $meter->name }}
                        </option>
                    @endforeach
                </select>
            </form>

            {{-- Live pill with auto-refresh countdown --}}
            <div class="live-pill">
                <span class="badge-dot"></span>
                Auto-refresh in <span id="refreshCountdown">30</span>s
            </div>

            <div class="header-actions">
                {{-- Manual refresh button — triggers immediate fetch --}}
                <button class="refresh-btn" id="manualRefreshBtn" title="Refresh now">
                    ↻ &nbsp;Refresh Now
                </button>

                <a class="refresh-btn header-link" href="/devices/manage">
                    Manage Meters
                </a>
            </div>
        </div>
    </header>

    <div class="status-stack">
        <div
            id="deviceHealthBanner"
            class="status-banner status-banner--{{ $deviceHealth['status'] }}{{ $deviceHealth['status'] === 'online' ? ' is-hidden' : '' }}"
            role="status"
            aria-live="polite"
        >
            {{ $deviceHealth['message'] }}
        </div>

        <div
            id="deviceIssueBanner"
            class="status-banner status-banner--{{ $deviceIssue['status'] }}{{ in_array($deviceIssue['status'], ['error', 'recovered'], true) ? '' : ' is-hidden' }}"
            role="status"
            aria-live="polite"
        >
            {{ $deviceIssue['message'] }}
        </div>

        <div
            id="deviceAvailabilityBanner"
            class="status-banner status-banner--{{ $deviceAvailability['status'] }}{{ $deviceAvailability['status'] === 'online' ? ' is-hidden' : '' }}"
            role="status"
            aria-live="polite"
        >
            {{ $deviceAvailability['message'] }}
        </div>

        <div id="connectionBanner" class="status-banner status-banner--error is-hidden" role="status" aria-live="polite"></div>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         KPI CARDS
         Always show the single most-recent reading.
         Updated by the auto-refresh cycle via updateKPIs().
    ══════════════════════════════════════════════════════════ --}}
    <div class="kpi-grid">
        <div class="kpi kpi--voltage">
            <span class="kpi-icon">⚡</span>
            <div class="kpi-label">Voltage</div>
            <div class="kpi-value" id="kpi-voltage">{{ data_get($currentSnapshot, 'voltage') ?? '—' }}</div>
            <div class="kpi-unit">V</div>
        </div>

        <div class="kpi kpi--current">
            <span class="kpi-icon">〜</span>
            <div class="kpi-label">Current</div>
            <div class="kpi-value" id="kpi-current">{{ data_get($currentSnapshot, 'current') ?? '—' }}</div>
            <div class="kpi-unit">A</div>
        </div>

        <div class="kpi kpi--power">
            <span class="kpi-icon">◈</span>
            <div class="kpi-label">Power</div>
            <div class="kpi-value" id="kpi-power">{{ data_get($currentSnapshot, 'power') ?? '—' }}</div>
            <div class="kpi-unit">W</div>
        </div>

        <div class="kpi kpi--energy-c">
            <span class="kpi-icon">◉</span>
            <div class="kpi-label">Computed Energy</div>
            <div class="kpi-value" id="kpi-energy-c">{{ data_get($currentSnapshot, 'energy_computed_wh') ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>

        <div class="kpi kpi--energy-p">
            <span class="kpi-icon">◎</span>
            <div class="kpi-label">PZEM Energy</div>
            <div class="kpi-value" id="kpi-energy-p">{{ data_get($currentSnapshot, 'energy_pzem_wh') ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>

        <div class="kpi kpi--freq">
            <span class="kpi-icon">≋</span>
            <div class="kpi-label">Frequency</div>
            <div class="kpi-value" id="kpi-freq">{{ data_get($currentSnapshot, 'frequency') ?? '—' }}</div>
            <div class="kpi-unit">Hz</div>
        </div>

        <div class="kpi kpi--pf">
            <span class="kpi-icon">∿</span>
            <div class="kpi-label">Power Factor</div>
            <div class="kpi-value" id="kpi-pf">{{ data_get($currentSnapshot, 'pf') ?? '—' }}</div>
            <div class="kpi-unit">PF</div>
        </div>

        <div class="kpi kpi--ts">
            <span class="kpi-icon">◷</span>
            <div class="kpi-label">Last Reading</div>
            {{-- Two-line: date on top, time bold below --}}
            <div class="kpi-value" id="kpi-ts" style="font-size:13px; line-height:1.5">
                {{ optional($currentSnapshotRecordedAt ? \Illuminate\Support\Carbon::parse($currentSnapshotRecordedAt) : null)->format('d M Y') ?? '—' }}<br>
                <span style="font-size:19px">
                    {{ optional($currentSnapshotRecordedAt ? \Illuminate\Support\Carbon::parse($currentSnapshotRecordedAt) : null)->format('H:i:s') ?? '' }}
                </span>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         CONTROLS BAR
         Range buttons on the left, "N new readings" badge on the right
    ══════════════════════════════════════════════════════════ --}}
    <div class="controls-bar">
        <div class="range-bar">
            <span class="range-label">Range</span>

            {{-- Each button sets data-range which is sent to the API --}}
            <button class="range-btn active" data-range="1h">1H</button>
            <button class="range-btn"        data-range="6h">6H</button>
            <button class="range-btn"        data-range="24h">24H</button>
            <button class="range-btn"        data-range="today">Today</button>

            <div class="range-divider"></div>

            <button class="range-btn" data-range="7d">7 Days</button>
        </div>

        {{-- Shown briefly after a silent background refresh finds new rows --}}
        <span class="new-badge" id="newBadge">— new readings</span>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         CHARTS SECTION
         Wrapped in .section-wrap so the spinner can overlay it
    ══════════════════════════════════════════════════════════ --}}
    <div class="section-wrap">
        <div class="loading-overlay" id="chartsLoader">
            <div class="spinner"></div>
        </div>

        <div class="charts-grid">

            {{-- Full-width: Voltage & Current on dual Y-axes --}}
            <div class="chart-card chart-wide">
                <div class="chart-title">⚡ Voltage &amp; Current — Dual Axis</div>
                <div class="chart-wrap">
                    <canvas id="chartVC"></canvas>
                </div>
            </div>

            {{-- Power consumption over time --}}
            <div class="chart-card">
                <div class="chart-title">◈ Active Power (W)</div>
                <div class="chart-wrap">
                    <canvas id="chartPow"></canvas>
                </div>
            </div>

            {{-- Computed vs PZEM energy — lets you spot calibration drift --}}
            <div class="chart-card">
                <div class="chart-title">◉ Energy — Computed vs PZEM (Wh)</div>
                <div class="chart-wrap">
                    <canvas id="chartEng"></canvas>
                </div>
            </div>

            {{-- Frequency — Y-axis locked to 45–65 Hz for clarity --}}
            <div class="chart-card">
                <div class="chart-title">≋ Grid Frequency (Hz)</div>
                <div class="chart-wrap">
                    <canvas id="chartFrq"></canvas>
                </div>
            </div>

            {{-- Power Factor bars colour-coded: green ≥0.9, amber ≥0.7, red <0.7 --}}
            <div class="chart-card">
                <div class="chart-title">∿ Power Factor</div>
                <div class="chart-wrap">
                    <canvas id="chartPF"></canvas>
                </div>
            </div>

        </div>
    </div>{{-- /.section-wrap (charts) --}}

    {{-- ══════════════════════════════════════════════════════════
         READINGS TABLE
         Newest row at top. Scrollable. New rows flash on arrival.
    ══════════════════════════════════════════════════════════ --}}
    <div class="section-head">
        <span class="section-title">Readings</span>
        <span class="table-meta">
            Showing <strong id="rowCount">—</strong> readings
            &nbsp;·&nbsp; range: <strong id="rangeLabel">1H</strong>
        </span>
    </div>

    <div class="section-wrap">
        <div class="loading-overlay" id="tableLoader">
            <div class="spinner"></div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Recorded At</th>
                        <th>Voltage (V)</th>
                        <th>Current (A)</th>
                        <th>Power (W)</th>
                        <th>Comp. Energy (Wh)</th>
                        <th>PZEM Energy (Wh)</th>
                        <th>Frequency (Hz)</th>
                        <th>PF</th>
                    </tr>
                </thead>
                <tbody id="readings-body">
                    <tr><td colspan="8"><div class="empty-state">Loading…</div></td></tr>
                </tbody>
            </table>
        </div>
    </div>{{-- /.section-wrap (table) --}}

</div>{{-- /.shell --}}

{{-- ══════════════════════════════════════════════════════════════════════
     JAVASCRIPT
     All dashboard logic lives here in one self-contained <script> block.
     Sections are clearly separated and documented.
══════════════════════════════════════════════════════════════════════ --}}
<script>
"use strict";

/* ═══════════════════════════════════════════════════════════════════════
 * 1. CONFIGURATION
 * Change DEVICE_ID or REFRESH_INTERVAL here if needed.
 * ═══════════════════════════════════════════════════════════════════════ */

/** The device ID injected by Blade — used to build the API URL. */
const DEVICE_ID = {{ $device->id }};

/** Base API endpoint. Query params are appended dynamically. */
const API_BASE = `/api/devices/${DEVICE_ID}/readings`;
const STATUS_API_BASE = `/api/devices/${DEVICE_ID}/status`;

/** Initial health snapshot rendered by Blade. */
const INITIAL_DEVICE_HEALTH = @json($deviceHealth);

/** Initial availability snapshot rendered by Blade. */
const INITIAL_DEVICE_AVAILABILITY = @json($deviceAvailability);

/** Initial payload issue snapshot rendered by Blade. */
const INITIAL_DEVICE_ISSUE = @json($deviceIssue);

/** Range-independent current KPI snapshot rendered by Blade. */
const INITIAL_CURRENT_SNAPSHOT = @json($currentSnapshot);

/**
 * How often (in seconds) to poll for new data silently in the background.
 * The first load always shows a full spinner; subsequent polls are silent.
 */
const REFRESH_INTERVAL = 30; // seconds


/* ═══════════════════════════════════════════════════════════════════════
 * 2. SHARED STATE
 * These variables are read/written across multiple functions.
 * ═══════════════════════════════════════════════════════════════════════ */

/** Currently selected range key (matches data-range on buttons). */
let activeRange = '1h';

/**
 * Tie-breaker for incremental refreshes when two rows share the same
 * recorded-at second.
 */
let lastKnownId = 0;

/** Latest effective recorded-at timestamp known to the dashboard. */
let lastKnownRecordedAt = null;

/**
 * In-memory array of ALL readings for the current range.
 * Stored newest-first (index 0 = most recent).
 * Charts use it in reversed (oldest-first) order.
 */
let allReadings = [];

/** Reference to the setInterval timer so we can clear/restart it. */
let autoRefreshTimer = null;

/** Countdown value shown in the live-pill. */
let countdownSeconds = REFRESH_INTERVAL;

/** Latest successful telemetry timestamp used to compute live health state. */
let currentLastSeenAt = INITIAL_DEVICE_HEALTH.last_seen_at;

/** Current MQTT availability state for the selected device. */
let currentAvailabilityState = INITIAL_DEVICE_AVAILABILITY;

/** Active payload issue state for the selected device. */
let currentIssueState = INITIAL_DEVICE_ISSUE;

/** Current KPI snapshot stays independent from the selected chart range. */
let currentSnapshot = INITIAL_CURRENT_SNAPSHOT;


/* ═══════════════════════════════════════════════════════════════════════
 * 3. CHART INITIALISATION
 * All five charts are created once with empty data.
 * They are updated in-place every time new data arrives.
 * ═══════════════════════════════════════════════════════════════════════ */

/* Apply dark-theme defaults globally to every Chart.js instance */
Chart.defaults.color       = '#64748b';
Chart.defaults.borderColor = '#1f2d45';
Chart.defaults.font.family = "'Space Mono', monospace";
Chart.defaults.font.size   = 10;

/**
 * Shared base options reused by all charts.
 * Individual charts can spread and override as needed.
 */
const BASE_OPTS = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 450, easing: 'easeInOutQuart' },
    plugins: {
        legend: { labels: { boxWidth: 10, padding: 16 } },
        tooltip: {
            backgroundColor: '#111827',
            borderColor:     '#1f2d45',
            borderWidth:      1,
            padding:          10,
        },
    },
    scales: {
        x: {
            ticks: { maxTicksLimit: 8, maxRotation: 0, color: '#475569' },
            grid:  { color: 'rgba(255,255,255,.04)' },
        },
        y: {
            grid:  { color: 'rgba(255,255,255,.04)' },
            ticks: { color: '#475569' },
        },
    },
};

/**
 * Helper: returns Chart.js dataset style properties for a filled area line.
 * @param {string} color  — hex or css colour string
 */
const areaStyle = (color) => ({
    fill:              true,
    backgroundColor:   `${color}18`,  // 18 = ~10% opacity in hex
    borderColor:        color,
    borderWidth:        2,
    pointRadius:        0,
    pointHoverRadius:   4,
    tension:            0.4,           // slight bezier smoothing
});

/* --- Chart 1: Voltage + Current (dual Y-axis) ---------------------- */
const chartVC = new Chart(document.getElementById('chartVC'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [
            { label: 'Voltage (V)', data: [], ...areaStyle('#00e5ff'), yAxisID: 'yV' },
            { label: 'Current (A)', data: [], ...areaStyle('#7c3aed'), yAxisID: 'yC' },
        ],
    },
    options: {
        ...BASE_OPTS,
        scales: {
            x:  BASE_OPTS.scales.x,
            /* Left axis — Voltage */
            yV: { position: 'left',  grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#00e5ff' } },
            /* Right axis — Current (no grid lines to avoid clutter) */
            yC: { position: 'right', grid: { drawOnChartArea: false },         ticks: { color: '#7c3aed' } },
        },
    },
});

/* --- Chart 2: Active Power ------------------------------------------ */
const chartPow = new Chart(document.getElementById('chartPow'), {
    type: 'line',
    data: { labels: [], datasets: [{ label: 'Power (W)', data: [], ...areaStyle('#f59e0b') }] },
    options: BASE_OPTS,
});

/* --- Chart 3: Energy Comparison ------------------------------------- */
const chartEng = new Chart(document.getElementById('chartEng'), {
    type: 'line',
    data: {
        labels: [],
        datasets: [
            { label: 'Computed (Wh)', data: [], ...areaStyle('#10b981') },
            { label: 'PZEM (Wh)',     data: [], ...areaStyle('#3b82f6') },
        ],
    },
    options: BASE_OPTS,
});

/* --- Chart 4: Frequency (Y-axis locked 45–65 Hz) -------------------- */
const chartFrq = new Chart(document.getElementById('chartFrq'), {
    type: 'line',
    data: { labels: [], datasets: [{ label: 'Frequency (Hz)', data: [], ...areaStyle('#ec4899') }] },
    options: {
        ...BASE_OPTS,
        scales: {
            ...BASE_OPTS.scales,
            y: { ...BASE_OPTS.scales.y, min: 45, max: 65 },
        },
    },
});

/* --- Chart 5: Power Factor (colour-coded bar) ----------------------- */
const chartPF = new Chart(document.getElementById('chartPF'), {
    type: 'bar',
    data: {
        labels: [],
        datasets: [{
            label:           'Power Factor',
            data:            [],
            backgroundColor: [],   // filled dynamically based on value
            borderColor:     [],
            borderWidth:      1,
            borderRadius:     4,
        }],
    },
    options: {
        ...BASE_OPTS,
        scales: {
            ...BASE_OPTS.scales,
            y: { ...BASE_OPTS.scales.y, min: 0, max: 1 },
        },
    },
});


/* ═══════════════════════════════════════════════════════════════════════
 * 4. FORMATTING HELPERS
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Returns an X-axis label string appropriate for the selected range.
 * - 1H / 6H  →  HH:MM:SS  (every second matters)
 * - 24H / Today → HH:MM   (minute-level is enough)
 * - 7D        →  DD Mon   (show the date instead of time)
 *
 * @param {string} dateStr  — ISO datetime string from the API
 * @param {string} range    — active range key
 * @returns {string}
 */
function fmtChartLabel(dateStr, range) {
    const d = new Date(dateStr);
    if (range === '7d')
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
    if (range === '24h' || range === 'today')
        return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    // 1h, 6h
    return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

/**
 * Returns a human-readable datetime string for the table.
 * For 7D range we omit seconds to keep the column narrow.
 *
 * @param {string} dateStr
 * @param {string} range
 * @returns {string}
 */
function fmtTableCell(dateStr, range) {
    const d = new Date(dateStr);
    if (range === '7d')
        return d.toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
    return d.toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

/**
 * Format a timestamp for operator-facing date/time labels.
 *
 * @param {string|null|undefined} dateStr
 * @returns {Object} Object with `date` and `time` string keys.
 */
function formatTimestampParts(dateStr) {
    const parsed = dateStr ? new Date(dateStr) : null;

    if (!parsed || Number.isNaN(parsed.valueOf())) {
        return { date: '—', time: '' };
    }

    return {
        date: parsed.toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }),
        time: parsed.toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    };
}

/**
 * Parse a timestamp if possible, otherwise return null.
 *
 * @param {string|null|undefined} dateStr
 * @returns {Date|null}
 */
function parseTimestamp(dateStr) {
    if (!dateStr) {
        return null;
    }

    const parsed = new Date(dateStr);

    return Number.isNaN(parsed.valueOf()) ? null : parsed;
}

/**
 * Sort readings newest-first by effective recorded time, then by id.
 *
 * @param {object} a
 * @param {object} b
 * @returns {number}
 */
function compareReadingsNewestFirst(a, b) {
    const aTime = parseTimestamp(a?.created_at)?.getTime() ?? 0;
    const bTime = parseTimestamp(b?.created_at)?.getTime() ?? 0;

    if (aTime !== bTime) {
        return bTime - aTime;
    }

    return Number(b?.id ?? 0) - Number(a?.id ?? 0);
}

/**
 * Update the incremental refresh cursor from the latest row in API order.
 *
 * @param {Array} readings
 */
function syncReadingsCursor(readings) {
    if (!readings.length) {
        return;
    }

    const latestReading = readings[readings.length - 1];

    lastKnownId = Number(latestReading?.id ?? 0);
    lastKnownRecordedAt = latestReading?.created_at ?? null;
}

/**
 * Merge incoming readings into the in-memory store without duplicating ids.
 * Updated rows are re-sorted by their effective recorded-at timestamp.
 *
 * @param {Array} incomingReadings
 */
function mergeIncomingReadings(incomingReadings) {
    const readingsById = new Map(
        allReadings.map(reading => [Number(reading.id), reading])
    );

    incomingReadings.forEach(reading => {
        const readingId = Number(reading.id);
        const existing = readingsById.get(readingId) ?? {};

        readingsById.set(readingId, {
            ...existing,
            ...reading,
        });
    });

    allReadings = Array.from(readingsById.values()).sort(compareReadingsNewestFirst);
}

/**
 * Build a KPI snapshot from a reading-like payload.
 *
 * @param {object|null} reading
 * @param {string|null|undefined} recordedAt
 * @returns {object|null}
 */
function makeSnapshotFromReading(reading, recordedAt = null) {
    if (!reading) {
        return null;
    }

    return {
        voltage: reading.voltage ?? null,
        current: reading.current ?? null,
        power: reading.power ?? null,
        energy_computed_wh: reading.energy_computed_wh ?? null,
        energy_pzem_wh: reading.energy_pzem_wh ?? null,
        frequency: reading.frequency ?? null,
        pf: reading.pf ?? null,
        recorded_at: recordedAt ?? reading.created_at ?? reading.received_at ?? null,
    };
}

/**
 * Maps a power-factor value to a colour.
 * ≥ 0.9  → green   (good)
 * ≥ 0.7  → amber   (acceptable)
 * < 0.7  → red     (poor)
 *
 * @param {number} v   — PF value 0–1
 * @param {number} alpha  — 0=solid, 0.4=transparent fill
 * @returns {string}  CSS colour string
 */
function pfColor(v, alpha = 1) {
    const [r, g, b] = v >= 0.9 ? [16, 185, 129]   // green
                    : v >= 0.7 ? [245, 158, 11]    // amber
                               : [239, 68, 68];    // red
    return alpha < 1
        ? `rgba(${r},${g},${b},${alpha})`
        : `rgb(${r},${g},${b})`;
}

/**
 * Render seconds as a short age string for health messages.
 *
 * @param {number} totalSeconds
 * @returns {string}
 */
function formatElapsedSeconds(totalSeconds) {
    if (totalSeconds < 60) {
        return `${totalSeconds}s`;
    }

    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (minutes < 60) {
        return seconds === 0 ? `${minutes}m` : `${minutes}m ${seconds}s`;
    }

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (hours < 24) {
        return remainingMinutes === 0 ? `${hours}h` : `${hours}h ${remainingMinutes}m`;
    }

    const days = Math.floor(hours / 24);
    const remainingHours = hours % 24;

    return remainingHours === 0 ? `${days}d` : `${days}d ${remainingHours}h`;
}

/**
 * Compute the current device health state from the latest telemetry timestamp.
 *
 * @returns {object}
 */
function computeDeviceHealthState() {
    const staleAfterSeconds = Number(INITIAL_DEVICE_HEALTH.stale_after_seconds ?? 180);
    const downAfterSeconds = Number(INITIAL_DEVICE_HEALTH.down_after_seconds ?? 600);

    if (!INITIAL_DEVICE_HEALTH.is_enabled) {
        return {
            status: 'disabled',
            label: 'Disabled',
            message: 'Monitoring is disabled for this meter.',
        };
    }

    if (!currentLastSeenAt) {
        return {
            status: 'never_seen',
            label: 'Never Seen',
            message: 'No telemetry has been received from this meter yet.',
        };
    }

    const parsedLastSeenAt = new Date(currentLastSeenAt);

    if (Number.isNaN(parsedLastSeenAt.valueOf())) {
        return {
            status: 'never_seen',
            label: 'Never Seen',
            message: 'No telemetry has been received from this meter yet.',
        };
    }

    const secondsSinceLastSeen = Math.max(0, Math.floor((Date.now() - parsedLastSeenAt.getTime()) / 1000));
    const ageText = formatElapsedSeconds(secondsSinceLastSeen);

    if (secondsSinceLastSeen >= downAfterSeconds) {
        return {
            status: 'down',
            label: 'Down',
            message: `Meter appears down. No telemetry has been received for ${ageText}.`,
        };
    }

    if (secondsSinceLastSeen >= staleAfterSeconds) {
        return {
            status: 'stale',
            label: 'Stale',
            message: `Telemetry is delayed. Last reading was ${ageText} ago.`,
        };
    }

    return {
        status: 'online',
        label: 'Online',
        message: `Meter is live. Telemetry was received ${ageText} ago.`,
    };
}

/**
 * Apply the computed device health to the badge and banner.
 *
 * @param {object} healthState
 */
function applyDeviceHealthState(healthState) {
    const labelEl = document.getElementById('deviceHealthLabel');
    const bannerEl = document.getElementById('deviceHealthBanner');

    if (labelEl) {
        labelEl.textContent = healthState.label;
        labelEl.className = `health-pill health-pill--${healthState.status}`;
    }

    if (!bannerEl) {
        return;
    }

    bannerEl.textContent = healthState.message;
    bannerEl.className = `status-banner status-banner--${healthState.status}`;

    if (healthState.status === 'online') {
        bannerEl.classList.add('is-hidden');
    }
}

/** Recompute and apply meter health from the current last-seen timestamp. */
function refreshDeviceHealth() {
    const healthState = computeDeviceHealthState();

    applyDeviceHealthState(healthState);
    refreshAvailabilityFromHealth(healthState);

    if (currentIssueState?.status === 'recovered' && healthState.status !== 'online') {
        currentIssueState = {
            ...(currentIssueState ?? {}),
            status: 'ok',
            label: 'No Issue',
            message: 'No active payload issues.',
            has_issue: false,
        };

        applyDeviceIssueState(currentIssueState);
    }
}

/**
 * Apply the availability state to the header pill and the dedicated banner.
 *
 * @param {object|null} availabilityState
 */
function applyDeviceAvailabilityState(availabilityState) {
    const labelEl = document.getElementById('deviceAvailabilityLabel');
    const bannerEl = document.getElementById('deviceAvailabilityBanner');

    if (labelEl && availabilityState) {
        labelEl.textContent = availabilityState.label ?? 'Unknown';
        labelEl.className = `health-pill health-pill--${availabilityState.status ?? 'unknown'}`;
    }

    if (!bannerEl || !availabilityState) {
        return;
    }

    bannerEl.textContent = availabilityState.message ?? 'No MQTT availability message has been received for this meter yet.';
    bannerEl.className = `status-banner status-banner--${availabilityState.status ?? 'unknown'}`;

    if (availabilityState.status === 'online') {
        bannerEl.classList.add('is-hidden');
    }
}

/**
 * Keep "online" availability in sync with the live health timer so a meter can
 * become "silent" without waiting for the next status poll.
 *
 * @param {object} healthState
 */
function refreshAvailabilityFromHealth(healthState) {
    if (!currentAvailabilityState) {
        return;
    }

    if (
        !['online', 'heartbeat'].includes(currentAvailabilityState.raw_status ?? '')
        && !['online', 'silent'].includes(currentAvailabilityState.status ?? '')
    ) {
        return;
    }

    const nextStatus = ['stale', 'down'].includes(healthState.status) ? 'silent' : 'online';

    if (currentAvailabilityState.status === nextStatus) {
        return;
    }

    currentAvailabilityState = {
        ...currentAvailabilityState,
        status: nextStatus,
        label: nextStatus === 'silent' ? 'Silent' : 'Online',
        message: nextStatus === 'silent'
            ? 'MQTT availability reports this meter online, but telemetry is currently delayed or down.'
            : 'MQTT availability reports this meter online.',
    };

    applyDeviceAvailabilityState(currentAvailabilityState);
}

/**
 * Apply the payload issue state to the dedicated dashboard banner.
 *
 * @param {object|null} issueState
 */
function applyDeviceIssueState(issueState) {
    const bannerEl = document.getElementById('deviceIssueBanner');

    if (!bannerEl || !issueState) {
        return;
    }

    bannerEl.textContent = issueState.message ?? 'No active payload issues.';
    bannerEl.className = `status-banner status-banner--${issueState.status ?? 'ok'}`;

    if (!issueState.has_issue && issueState.status !== 'recovered') {
        bannerEl.classList.add('is-hidden');
    }
}

/**
 * Clear the active issue banner immediately when valid telemetry resumes.
 *
 * @param {string|null|undefined} recoveredAt
 */
function clearActiveIssueState(recoveredAt = null) {
    if (!currentIssueState?.has_issue && currentIssueState?.status !== 'recovered') {
        return;
    }

    if (!currentIssueState?.has_issue && currentIssueState?.status === 'recovered') {
        currentIssueState = {
            ...(currentIssueState ?? {}),
            status: 'ok',
            label: 'No Issue',
            message: 'No active payload issues.',
            has_issue: false,
        };

        applyDeviceIssueState(currentIssueState);
        return;
    }

    currentIssueState = {
        ...(currentIssueState ?? {}),
        status: 'recovered',
        label: 'Recovered',
        message: 'Valid telemetry resumed.',
        has_issue: false,
        last_recovered_at: recoveredAt ?? new Date().toISOString(),
    };

    applyDeviceIssueState(currentIssueState);
}

/**
 * Fetch the latest runtime status snapshots for the selected device.
 *
 * @returns {Promise<object>}
 */
async function fetchDeviceStatus() {
    const response = await fetch(STATUS_API_BASE, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`Status API error: ${response.status} ${response.statusText}`);
    }

    return response.json();
}

/**
 * Apply the latest runtime status payload returned by the status endpoint.
 *
 * @param {object|null} statusPayload
 */
function applyRuntimeStatus(statusPayload) {
    if (!statusPayload) {
        return;
    }

    if (statusPayload.availability) {
        currentAvailabilityState = statusPayload.availability;
        applyDeviceAvailabilityState(currentAvailabilityState);
    }

    if (statusPayload.health?.last_seen_at) {
        updateLastSeen(statusPayload.health.last_seen_at);
    } else {
        currentLastSeenAt = statusPayload.health?.last_seen_at ?? currentLastSeenAt;
        refreshDeviceHealth();
    }

    if (statusPayload.issue) {
        currentIssueState = statusPayload.issue;
        applyDeviceIssueState(currentIssueState);
    }

    if (statusPayload.current_snapshot) {
        updateCurrentSnapshot(statusPayload.current_snapshot);
    }
}

/**
 * Surface dashboard-side refresh problems without confusing them with meter health.
 *
 * @param {string} message
 */
function showConnectionIssue(message) {
    const banner = document.getElementById('connectionBanner');

    if (!banner) {
        return;
    }

    banner.textContent = message;
    banner.classList.remove('is-hidden');
}

/** Hide the dashboard connection banner after a successful refresh. */
function clearConnectionIssue() {
    const banner = document.getElementById('connectionBanner');

    if (!banner) {
        return;
    }

    banner.textContent = '';
    banner.classList.add('is-hidden');
}


/* ═══════════════════════════════════════════════════════════════════════
 * 5. CHART UPDATER
 * Receives readings in oldest-first order (as the API returns them)
 * and pushes data into every chart at once.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Rebuilds all five charts from the provided readings array.
 * readings must be sorted oldest → newest (left to right on the chart).
 *
 * @param {Array}  readings  — array of reading objects
 * @param {string} range     — current range key (for label formatting)
 */
function updateCharts(readings, range) {
    // Build parallel arrays — Chart.js needs one array per data series
    const labels = readings.map(r => fmtChartLabel(r.created_at, range));
    const V      = readings.map(r => parseFloat(r.voltage)             || 0);
    const A      = readings.map(r => parseFloat(r.current)             || 0);
    const W      = readings.map(r => parseFloat(r.power)              || 0);
    const eC     = readings.map(r => parseFloat(r.energy_computed_wh) || 0);
    const eP     = readings.map(r => parseFloat(r.energy_pzem_wh)     || 0);
    const hz     = readings.map(r => parseFloat(r.frequency)          || 0);
    const pf     = readings.map(r => parseFloat(r.pf)                 || 0);

    /* Voltage / Current */
    chartVC.data.labels           = labels;
    chartVC.data.datasets[0].data = V;
    chartVC.data.datasets[1].data = A;
    chartVC.update();

    /* Power */
    chartPow.data.labels           = labels;
    chartPow.data.datasets[0].data = W;
    chartPow.update();

    /* Energy */
    chartEng.data.labels           = labels;
    chartEng.data.datasets[0].data = eC;
    chartEng.data.datasets[1].data = eP;
    chartEng.update();

    /* Frequency */
    chartFrq.data.labels           = labels;
    chartFrq.data.datasets[0].data = hz;
    chartFrq.update();

    /* Power Factor — colour each bar individually */
    chartPF.data.labels                       = labels;
    chartPF.data.datasets[0].data            = pf;
    chartPF.data.datasets[0].backgroundColor = pf.map(v => pfColor(v, 0.4));
    chartPF.data.datasets[0].borderColor     = pf.map(v => pfColor(v));
    chartPF.update();
}


/* ═══════════════════════════════════════════════════════════════════════
 * 6. KPI CARD UPDATER
 * Keeps the top KPI strip tied to the latest known device snapshot,
 * not the currently selected chart/table range.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Updates the 8 live KPI cards with the current snapshot values.
 */
function updateKPIs() {
    const snapshot = currentSnapshot;
    const timestampParts = formatTimestampParts(snapshot?.recorded_at);

    document.getElementById('kpi-voltage').textContent  = snapshot?.voltage            ?? '—';
    document.getElementById('kpi-current').textContent  = snapshot?.current            ?? '—';
    document.getElementById('kpi-power').textContent    = snapshot?.power              ?? '—';
    document.getElementById('kpi-energy-c').textContent = snapshot?.energy_computed_wh ?? '—';
    document.getElementById('kpi-energy-p').textContent = snapshot?.energy_pzem_wh     ?? '—';
    document.getElementById('kpi-freq').textContent     = snapshot?.frequency          ?? '—';
    document.getElementById('kpi-pf').textContent       = snapshot?.pf                 ?? '—';
    document.getElementById('kpi-ts').innerHTML =
        `${timestampParts.date}<br><span style="font-size:19px">${timestampParts.time}</span>`;
}

/**
 * Promote a reading to the current KPI snapshot when it is at least as recent
 * as the snapshot we already have. This keeps the KPI cards stable while the
 * chart/table range is changed underneath them.
 *
 * @param {object|null} nextSnapshot
 */
function updateCurrentSnapshot(nextSnapshot) {
    if (!nextSnapshot) {
        return;
    }

    const nextRecordedAt = parseTimestamp(nextSnapshot.recorded_at);
    const currentRecordedAt = parseTimestamp(currentSnapshot?.recorded_at);

    if (!currentSnapshot || !currentRecordedAt) {
        currentSnapshot = nextSnapshot;
        updateKPIs();
        return;
    }

    if (!nextRecordedAt || nextRecordedAt < currentRecordedAt) {
        return;
    }

    currentSnapshot = nextSnapshot;
    updateKPIs();
}

/**
 * Updates the header "Last Seen" timestamp when a realtime event arrives.
 *
 * @param {string|null|undefined} dateStr
 */
function updateLastSeen(dateStr) {
    if (!dateStr) return;

    currentLastSeenAt = dateStr;

    const d = new Date(dateStr);

    document.getElementById('last_seen').textContent = Number.isNaN(d.valueOf())
        ? dateStr
        : d.toLocaleString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });

    refreshDeviceHealth();
}

/**
 * Normalizes the broadcast payload into the same shape used by the readings API.
 *
 * @param {object} eventPayload
 * @returns {object|null}
 */
function normalizeRealtimeReading(eventPayload) {
    if (!eventPayload || Number(eventPayload.device_id) !== DEVICE_ID || !eventPayload.reading) {
        return null;
    }

    const reading = eventPayload.reading;
    const readingId = Number(reading.id ?? 0);

    if (!readingId) {
        return null;
    }

    return {
        id: readingId,
        created_at: reading.received_at ?? reading.created_at ?? new Date().toISOString(),
        received_at: reading.received_at ?? null,
        voltage: reading.voltage ?? null,
        current: reading.current ?? null,
        power: reading.power ?? null,
        energy_computed_wh: reading.energy_computed_wh ?? null,
        energy_pzem_wh: reading.energy_pzem_wh ?? null,
        frequency: reading.frequency ?? null,
        pf: reading.pf ?? null,
    };
}

/**
 * Normalize a realtime event into the range-independent KPI snapshot shape.
 *
 * @param {object} eventPayload
 * @returns {object|null}
 */
function normalizeRealtimeSnapshot(eventPayload) {
    if (!eventPayload || Number(eventPayload.device_id) !== DEVICE_ID || !eventPayload.reading) {
        return null;
    }

    return makeSnapshotFromReading(
        eventPayload.reading,
        eventPayload.last_seen_at ?? eventPayload.reading.received_at ?? eventPayload.reading.created_at ?? null,
    );
}

/**
 * Determines whether a reading falls inside the currently selected time window.
 *
 * @param {object} reading
 * @param {string} range
 * @returns {boolean}
 */
function readingFallsInRange(reading, range) {
    const createdAt = new Date(reading.created_at);

    if (Number.isNaN(createdAt.valueOf())) {
        return true;
    }

    const now = new Date();

    switch (range) {
        case '1h':
            return createdAt >= new Date(now.getTime() - 60 * 60 * 1000);
        case '6h':
            return createdAt >= new Date(now.getTime() - 6 * 60 * 60 * 1000);
        case '24h':
            return createdAt >= new Date(now.getTime() - 24 * 60 * 60 * 1000);
        case 'today': {
            const startOfToday = new Date(now);
            startOfToday.setHours(0, 0, 0, 0);
            return createdAt >= startOfToday;
        }
        case '7d':
            return createdAt >= new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
        default:
            return true;
    }
}

/**
 * Applies a realtime reading to the dashboard without waiting for the next poll.
 *
 * @param {object} eventPayload
 */
function ingestRealtimeReading(eventPayload) {
    const reading = normalizeRealtimeReading(eventPayload);

    if (!reading) {
        return;
    }

    updateLastSeen(eventPayload.last_seen_at ?? eventPayload.reading.received_at);

    // Out-of-order packets are still useful history, but the backend marks
    // them so they cannot move the range-independent KPI strip backward.
    if (eventPayload.latest_state_updated !== false) {
        updateCurrentSnapshot(normalizeRealtimeSnapshot(eventPayload));
    }

    clearActiveIssueState(eventPayload.last_seen_at ?? eventPayload.reading.received_at);
    restoreAvailabilityFromTelemetry();

    const existingReadingIndex = allReadings.findIndex(row => Number(row.id) === reading.id);

    if (existingReadingIndex !== -1) {
        mergeIncomingReadings([reading]);
        syncReadingsCursor([...allReadings].slice().reverse());

        updateCharts([...allReadings].reverse(), activeRange);
        updateTable(activeRange, 0);
        return;
    }

    if (!readingFallsInRange(reading, activeRange)) {
        return;
    }

    mergeIncomingReadings([reading]);
    syncReadingsCursor([...allReadings].slice().reverse());

    updateCharts([...allReadings].reverse(), activeRange);
    updateTable(activeRange, 1);
    updateKPIs();
    showNewBadge(1);
}

/**
 * If telemetry arrives after an offline/silent state, reflect that immediately.
 */
function restoreAvailabilityFromTelemetry() {
    if (!currentAvailabilityState || ['disabled', 'unknown'].includes(currentAvailabilityState.status ?? 'unknown')) {
        return;
    }

    const cameBackAfterOffline = currentAvailabilityState.status === 'offline' || currentAvailabilityState.raw_status === 'offline';

    currentAvailabilityState = {
        ...currentAvailabilityState,
        raw_status: 'online',
        status: 'online',
        label: 'Online',
        message: cameBackAfterOffline
            ? 'Telemetry resumed after the last offline availability signal.'
            : 'MQTT availability reports this meter online.',
    };

    applyDeviceAvailabilityState(currentAvailabilityState);
}

/**
 * Apply a realtime availability event without waiting for the next poll.
 *
 * @param {object} eventPayload
 */
function ingestAvailabilityUpdate(eventPayload) {
    if (!eventPayload || Number(eventPayload.device_id) !== Number(DEVICE_ID) || !eventPayload.availability) {
        return;
    }

    currentAvailabilityState = eventPayload.availability;
    applyDeviceAvailabilityState(currentAvailabilityState);

    if (eventPayload.health?.last_seen_at) {
        currentLastSeenAt = eventPayload.health.last_seen_at;
        refreshDeviceHealth();
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 7. TABLE UPDATER
 * Renders the table from allReadings (newest-first).
 * When prepending new rows after a background refresh, each new row
 * gets the .row-new class so it flashes briefly.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Rebuilds the entire readings table from allReadings.
 * allReadings is newest-first → newest row appears at the top of the table.
 *
 * @param {string} range        — current range (for date formatting)
 * @param {number} newRowCount  — how many rows at the start are "new"
 *                                (they get the flash animation)
 */
function updateTable(range, newRowCount = 0) {
    const tbody = document.getElementById('readings-body');

    // Update the row count and range label in the section header
    document.getElementById('rowCount').textContent = allReadings.length.toLocaleString();
    document.getElementById('rangeLabel').textContent = activeRange.toUpperCase();

    if (!allReadings.length) {
        tbody.innerHTML = `<tr><td colspan="8">
            <div class="empty-state">No readings found for this time range.</div>
        </td></tr>`;
        return;
    }

    tbody.innerHTML = allReadings.map((r, i) => {
        // The first newRowCount rows are newly arrived — give them the flash class
        const flashClass = (newRowCount > 0 && i < newRowCount) ? 'row-new' : '';
        return `
        <tr class="${flashClass}">
            <td>${fmtTableCell(r.created_at, range)}</td>
            <td>${r.voltage             ?? '—'}</td>
            <td>${r.current             ?? '—'}</td>
            <td>${r.power               ?? '—'}</td>
            <td>${r.energy_computed_wh  ?? '—'}</td>
            <td>${r.energy_pzem_wh      ?? '—'}</td>
            <td>${r.frequency           ?? '—'}</td>
            <td>${r.pf                  ?? '—'}</td>
        </tr>`;
    }).join('');
}


/* ═══════════════════════════════════════════════════════════════════════
 * 8. API FETCH HELPERS
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Fetches readings from the API.
 *
 * On a FULL LOAD (range change or first load):
 *   GET /api/devices/{id}/readings?range=1h
 *   → returns all readings in the window, oldest first
 *
 * On a BACKGROUND REFRESH:
 *   GET /api/devices/{id}/readings?range=1h&after_received_at=<timestamp>&after_id=<id>
 *   → returns only rows recorded after the latest row the UI has seen
 *   → if nothing new, returns an empty array
 *
 * @param {string}  range      — range key to send
 * @param {number}  afterId    — row id tie-breaker for same-second readings
 * @param {string|null} afterRecordedAt — effective recorded-at cursor
 * @returns {Promise<Array>}   — array of reading objects, oldest first
 * @throws  on network/parse errors
 */
async function fetchReadings(range, afterId = 0, afterRecordedAt = null) {
    let url = `${API_BASE}?range=${range}`;

    if (afterRecordedAt) {
        url += `&after_received_at=${encodeURIComponent(afterRecordedAt)}`;
        url += `&after_id=${afterId}`;
    } else if (afterId > 0) {
        url += `&after=${afterId}`;
    }

    const response = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });

    if (!response.ok) {
        throw new Error(`API error: ${response.status} ${response.statusText}`);
    }

    const json = await response.json();

    // Support both { readings: [...] } and plain [...] response shapes
    return Array.isArray(json) ? json : (json.readings ?? []);
}


/* ═══════════════════════════════════════════════════════════════════════
 * 9. FULL LOAD
 * Called when the page first loads or when the user switches range.
 * Shows the spinner overlay, replaces all data, resets the cursor.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Performs a complete data reload for the given range.
 * Displays a blocking spinner while loading.
 * Resets the incremental cursor so subsequent background fetches start fresh.
 *
 * @param {string} range  — range key to load
 */
async function fullLoad(range) {
    // Show spinners over both charts and table
    document.getElementById('chartsLoader').classList.add('show');
    document.getElementById('tableLoader').classList.add('show');

    try {
        const [readings, statusPayload] = await Promise.all([
            fetchReadings(range, 0),
            fetchDeviceStatus(),
        ]);

        /*
         * API returns oldest → newest. We want:
         *   - allReadings stored  newest-first  (table reads top=newest)
         *   - charts plotted      oldest-first  (time flows left → right)
         */
        allReadings = [...readings].sort(compareReadingsNewestFirst);
        syncReadingsCursor(readings);

        // Update the UI
        updateCharts(readings, range);      // pass oldest-first (as received)
        updateTable(range, 0);             // no flash on full load
        clearConnectionIssue();
        applyRuntimeStatus(statusPayload);

        if (!currentSnapshot) {
            updateCurrentSnapshot(makeSnapshotFromReading(readings[readings.length - 1] ?? null));
        }

    } catch (err) {
        console.error('[MeterDash] fullLoad failed:', err);
        showConnectionIssue('Dashboard connection issue. Readings could not be refreshed, so meter health is based on the last successful telemetry timestamp.');
        document.getElementById('readings-body').innerHTML =
            `<tr><td colspan="8"><div class="empty-state">
                ⚠ Failed to load data. Check console.
            </div></td></tr>`;
    } finally {
        document.getElementById('chartsLoader').classList.remove('show');
        document.getElementById('tableLoader').classList.remove('show');
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 10. BACKGROUND / SILENT REFRESH
 * Called every REFRESH_INTERVAL seconds.
 * Does NOT show a spinner — UI stays interactive.
 * Only fetches rows newer than lastKnownId.
 * Prepends new rows to allReadings, flashes them in the table,
 * and appends them to the charts.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Silently polls for new readings without blocking the UI.
 * - Sends ?after=<lastKnownId> so only new rows are returned.
 * - Prepends them to allReadings (so newest stays at index 0).
 * - Appends them to charts (so time still flows left → right).
 * - Shows a brief "N new readings" badge.
 */
async function backgroundRefresh() {
    try {
        // Fetch only rows newer than what we already have
        const [newRows, statusPayload] = await Promise.all([
            fetchReadings(activeRange, lastKnownId, lastKnownRecordedAt),
            fetchDeviceStatus(),
        ]);
        clearConnectionIssue();
        applyRuntimeStatus(statusPayload);

        if (!newRows.length) {
            // Nothing new — no UI update needed
            return;
        }

        syncReadingsCursor(newRows);

        /*
         * newRows is oldest→newest (API order).
         * Prepend reversed newRows to allReadings so allReadings stays newest-first.
         */
        const newRowsNewestFirst = [...newRows].sort(compareReadingsNewestFirst);
        mergeIncomingReadings(newRows);

        // Rebuild charts with the full dataset (oldest→newest order)
        const chartOrder = [...allReadings].reverse();
        updateCharts(chartOrder, activeRange);

        // Rebuild table — pass newRows.length so they get the flash animation
        updateTable(activeRange, newRowsNewestFirst.length);

        /*
         * KPI cards are driven by /status current_snapshot instead of the newest
         * polled row. That preserves the backend's monotonic latest-state rule
         * when delayed MQTT packets arrive after a newer sample.
         */

        // Show the "N new readings" badge briefly
        showNewBadge(newRowsNewestFirst.length);

        const latestReading = newRows[newRows.length - 1] ?? null;

        if (latestReading?.created_at) {
            updateLastSeen(latestReading.created_at);
        }

    } catch (err) {
        console.warn('[MeterDash] backgroundRefresh failed:', err);
        showConnectionIssue('Dashboard connection issue. Background refresh failed, so meter health is based on the last successful telemetry timestamp.');
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 11. AUTO-REFRESH TIMER WITH VISIBLE COUNTDOWN
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Starts (or restarts) the auto-refresh cycle.
 * A 1-second tick updates the countdown display.
 * Every REFRESH_INTERVAL seconds, backgroundRefresh() is called.
 */
function startAutoRefresh() {
    // Clear any existing timer before starting a new one
    stopAutoRefresh();

    countdownSeconds = REFRESH_INTERVAL;
    updateCountdownDisplay();
    refreshDeviceHealth();

    autoRefreshTimer = setInterval(async () => {
        countdownSeconds--;
        updateCountdownDisplay();
        refreshDeviceHealth();

        if (countdownSeconds <= 0) {
            // Time to refresh — reset countdown and fetch new data
            countdownSeconds = REFRESH_INTERVAL;
            await backgroundRefresh();
        }
    }, 1000); // tick every 1 second
}

/** Clears the auto-refresh interval. */
function stopAutoRefresh() {
    if (autoRefreshTimer !== null) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

/** Updates the countdown number shown inside the live pill. */
function updateCountdownDisplay() {
    const el = document.getElementById('refreshCountdown');
    if (el) el.textContent = countdownSeconds;
}


/* ═══════════════════════════════════════════════════════════════════════
 * 12. UI HELPERS
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Briefly shows the "N new readings" badge then fades it out.
 * @param {number} count
 */
function showNewBadge(count) {
    const badge = document.getElementById('newBadge');
    badge.textContent = `+${count} new ${count === 1 ? 'reading' : 'readings'}`;
    badge.classList.add('show');
    // Auto-hide after 4 seconds
    setTimeout(() => badge.classList.remove('show'), 4000);
}


/* ═══════════════════════════════════════════════════════════════════════
 * 13. EVENT WIRING
 * Range buttons + manual refresh button
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Wire up each range button.
 * Clicking a button:
 *   1. Marks it active (visual highlight)
 *   2. Sets activeRange
 *   3. Resets lastKnownId to 0 (so fullLoad fetches all data for the new range)
 *   4. Runs a fullLoad for the new range
 *   5. Restarts the auto-refresh timer from the beginning
 */
document.querySelectorAll('.range-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
        // Deactivate all buttons, activate this one
        document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        activeRange = btn.dataset.range;
        lastKnownId = 0;
        lastKnownRecordedAt = null;
        allReadings = [];

        await fullLoad(activeRange);
        startAutoRefresh();        // restart countdown from 30s
    });
});

/**
 * Manual refresh button: immediately fires a background refresh
 * and resets the countdown timer.
 */
document.getElementById('manualRefreshBtn').addEventListener('click', async () => {
    await backgroundRefresh();
    startAutoRefresh(); // restart timer so countdown resets to 30
});

window.addEventListener('meter-reading-updated', (event) => {
    // Ignore other meters so the selected dashboard never mixes device data.
    if (Number(event.detail.device_id) !== Number(DEVICE_ID)) {
        return;
    }

    ingestRealtimeReading(event.detail);
});

window.addEventListener('meter-availability-updated', (event) => {
    if (Number(event.detail.device_id) !== Number(DEVICE_ID)) {
        return;
    }

    ingestAvailabilityUpdate(event.detail);
});


/* ═══════════════════════════════════════════════════════════════════════
 * 14. BOOT — runs once when the page is ready
 * ═══════════════════════════════════════════════════════════════════════ */
(async () => {
    updateKPIs();
    applyDeviceAvailabilityState(currentAvailabilityState);
    refreshDeviceHealth();
    await fullLoad(activeRange);   // first data load (shows spinner)
    startAutoRefresh();            // begin the 30-second polling cycle
})();
</script>

</body>
</html>

