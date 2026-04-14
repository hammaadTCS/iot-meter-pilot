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
|   $device          — Device model (id, name, mqtt_topic, last_seen_at)
|   $device->latestState — latest DeviceReading row (all fields)
|   $recentReadings  — NOT used anymore; data loaded entirely via AJAX
|
| API endpoint consumed:
|   GET /api/devices/{id}/readings?range=1h&after=0
|   → Returns JSON array of readings, oldest first
|   → See DeviceReadingController.php for the backend implementation
|
--}}
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
        .meta-row span { font-family: var(--font-mono); color: var(--text); }

        /* Top-right pill showing auto-refresh countdown */
        .header-right {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
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
            </div>
        </div>

        <div class="header-right">
            {{-- Live pill with auto-refresh countdown --}}
            <div class="live-pill">
                <span class="badge-dot"></span>
                Auto-refresh in <span id="refreshCountdown">30</span>s
            </div>

            {{-- Manual refresh button — triggers immediate fetch --}}
            <button class="refresh-btn" id="manualRefreshBtn" title="Refresh now">
                ↻ &nbsp;Refresh Now
            </button>
        </div>
    </header>

    {{-- ══════════════════════════════════════════════════════════
         KPI CARDS
         Always show the single most-recent reading.
         Updated by the auto-refresh cycle via updateKPIs().
    ══════════════════════════════════════════════════════════ --}}
    <div class="kpi-grid">
        <div class="kpi kpi--voltage">
            <span class="kpi-icon">⚡</span>
            <div class="kpi-label">Voltage</div>
            <div class="kpi-value" id="kpi-voltage">{{ $device->latestState->voltage ?? '—' }}</div>
            <div class="kpi-unit">V</div>
        </div>

        <div class="kpi kpi--current">
            <span class="kpi-icon">〜</span>
            <div class="kpi-label">Current</div>
            <div class="kpi-value" id="kpi-current">{{ $device->latestState->current ?? '—' }}</div>
            <div class="kpi-unit">A</div>
        </div>

        <div class="kpi kpi--power">
            <span class="kpi-icon">◈</span>
            <div class="kpi-label">Power</div>
            <div class="kpi-value" id="kpi-power">{{ $device->latestState->power ?? '—' }}</div>
            <div class="kpi-unit">W</div>
        </div>

        <div class="kpi kpi--energy-c">
            <span class="kpi-icon">◉</span>
            <div class="kpi-label">Computed Energy</div>
            <div class="kpi-value" id="kpi-energy-c">{{ $device->latestState->energy_computed_wh ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>

        <div class="kpi kpi--energy-p">
            <span class="kpi-icon">◎</span>
            <div class="kpi-label">PZEM Energy</div>
            <div class="kpi-value" id="kpi-energy-p">{{ $device->latestState->energy_pzem_wh ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>

        <div class="kpi kpi--freq">
            <span class="kpi-icon">≋</span>
            <div class="kpi-label">Frequency</div>
            <div class="kpi-value" id="kpi-freq">{{ $device->latestState->frequency ?? '—' }}</div>
            <div class="kpi-unit">Hz</div>
        </div>

        <div class="kpi kpi--pf">
            <span class="kpi-icon">∿</span>
            <div class="kpi-label">Power Factor</div>
            <div class="kpi-value" id="kpi-pf">{{ $device->latestState->pf ?? '—' }}</div>
            <div class="kpi-unit">PF</div>
        </div>

        <div class="kpi kpi--ts">
            <span class="kpi-icon">◷</span>
            <div class="kpi-label">Last Reading</div>
            {{-- Two-line: date on top, time bold below --}}
            <div class="kpi-value" id="kpi-ts" style="font-size:13px; line-height:1.5">
                {{ optional($device->latestState->created_at)->format('d M Y') ?? '—' }}<br>
                <span style="font-size:19px">
                    {{ optional($device->latestState->created_at)->format('H:i:s') ?? '' }}
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
 * The highest reading ID we have received so far.
 * Sent as ?after=<id> on background refreshes so the backend
 * returns ONLY rows newer than this — avoids re-fetching old data.
 * Starts at 0 meaning "give me everything for this range".
 */
let lastKnownId = 0;

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
 * Picks the most recent reading from allReadings (index 0, since
 * allReadings is stored newest-first) and refreshes the KPI cards.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Updates the 8 live KPI cards with the most recent reading values.
 * Called after every fetch (full load or background refresh).
 */
function updateKPIs() {
    if (!allReadings.length) return;

    const latest = allReadings[0]; // index 0 = newest (we store newest-first)

    document.getElementById('kpi-voltage').textContent  = latest.voltage             ?? '—';
    document.getElementById('kpi-current').textContent  = latest.current             ?? '—';
    document.getElementById('kpi-power').textContent    = latest.power               ?? '—';
    document.getElementById('kpi-energy-c').textContent = latest.energy_computed_wh  ?? '—';
    document.getElementById('kpi-energy-p').textContent = latest.energy_pzem_wh      ?? '—';
    document.getElementById('kpi-freq').textContent     = latest.frequency           ?? '—';
    document.getElementById('kpi-pf').textContent       = latest.pf                  ?? '—';

    // Format the "Last Reading" card as two lines: date + time
    if (latest.created_at) {
        const d = new Date(latest.created_at);
        const date = d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        const time = d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('kpi-ts').innerHTML =
            `${date}<br><span style="font-size:19px">${time}</span>`;
    }
}

/**
 * Updates the header "Last Seen" timestamp when a realtime event arrives.
 *
 * @param {string|null|undefined} dateStr
 */
function updateLastSeen(dateStr) {
    if (!dateStr) return;

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
        created_at: reading.created_at ?? reading.received_at ?? new Date().toISOString(),
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

    if (allReadings.some(row => Number(row.id) === reading.id)) {
        return;
    }

    if (!readingFallsInRange(reading, activeRange)) {
        return;
    }

    allReadings = [reading, ...allReadings];
    lastKnownId = Math.max(lastKnownId, reading.id);

    updateCharts([...allReadings].reverse(), activeRange);
    updateTable(activeRange, 1);
    updateKPIs();
    showNewBadge(1);
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
 *   GET /api/devices/{id}/readings?range=1h&after=<lastKnownId>
 *   → returns only rows with id > lastKnownId, oldest first
 *   → if nothing new, returns an empty array
 *
 * @param {string}  range      — range key to send
 * @param {number}  afterId    — only return rows with id > this (0 = all)
 * @returns {Promise<Array>}   — array of reading objects, oldest first
 * @throws  on network/parse errors
 */
async function fetchReadings(range, afterId = 0) {
    let url = `${API_BASE}?range=${range}`;
    if (afterId > 0) url += `&after=${afterId}`;

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
 * Resets lastKnownId so subsequent background fetches start fresh.
 *
 * @param {string} range  — range key to load
 */
async function fullLoad(range) {
    // Show spinners over both charts and table
    document.getElementById('chartsLoader').classList.add('show');
    document.getElementById('tableLoader').classList.add('show');

    try {
        const readings = await fetchReadings(range, 0);

        /*
         * API returns oldest → newest. We want:
         *   - allReadings stored  newest-first  (table reads top=newest)
         *   - charts plotted      oldest-first  (time flows left → right)
         */
        allReadings = [...readings].reverse(); // newest at index 0

        // Track the highest id we have received
        if (readings.length) {
            lastKnownId = Math.max(...readings.map(r => r.id ?? 0));
        }

        // Update the UI
        updateCharts(readings, range);      // pass oldest-first (as received)
        updateTable(range, 0);             // no flash on full load
        updateKPIs();

    } catch (err) {
        console.error('[MeterDash] fullLoad failed:', err);
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
        const newRows = await fetchReadings(activeRange, lastKnownId);

        if (!newRows.length) {
            // Nothing new — no UI update needed
            return;
        }

        // Update the cursor to the newest id in this batch
        lastKnownId = Math.max(lastKnownId, ...newRows.map(r => r.id ?? 0));

        /*
         * newRows is oldest→newest (API order).
         * Prepend reversed newRows to allReadings so allReadings stays newest-first.
         */
        const newRowsNewestFirst = [...newRows].reverse();
        allReadings = [...newRowsNewestFirst, ...allReadings];

        // Rebuild charts with the full dataset (oldest→newest order)
        const chartOrder = [...allReadings].reverse();
        updateCharts(chartOrder, activeRange);

        // Rebuild table — pass newRows.length so they get the flash animation
        updateTable(activeRange, newRowsNewestFirst.length);

        // Update KPI cards with the latest reading
        updateKPIs();

        // Show the "N new readings" badge briefly
        showNewBadge(newRowsNewestFirst.length);

    } catch (err) {
        // Silent refresh: log the error but don't show anything to the user
        console.warn('[MeterDash] backgroundRefresh failed:', err);
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

    autoRefreshTimer = setInterval(async () => {
        countdownSeconds--;
        updateCountdownDisplay();

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

        activeRange  = btn.dataset.range;
        lastKnownId  = 0;          // reset cursor — fetch everything fresh
        allReadings  = [];         // clear local cache

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
    ingestRealtimeReading(event.detail);
});


/* ═══════════════════════════════════════════════════════════════════════
 * 14. BOOT — runs once when the page is ready
 * ═══════════════════════════════════════════════════════════════════════ */
(async () => {
    await fullLoad(activeRange);   // first data load (shows spinner)
    startAutoRefresh();            // begin the 30-second polling cycle
})();
</script>

</body>
</html>
{{-- <!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $device->name }} — Meter Pilot</title>
    @vite(['resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
        :root {
            --bg:        #0b0f1a;
            --surface:   #111827;
            --surface2:  #1a2235;
            --border:    #1f2d45;
            --accent:    #00e5ff;
            --accent2:   #7c3aed;
            --accent3:   #f59e0b;
            --green:     #10b981;
            --red:       #ef4444;
            --text:      #e2e8f0;
            --muted:     #64748b;
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

        /* Animated grid background */
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

        .shell {
            position: relative;
            z-index: 1;
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 24px 64px;
        }

        /* ── Header ── */
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

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-bottom: 4px;
        }
        .badge-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 8px var(--accent);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .5; transform: scale(.8); }
        }

        h1 {
            font-family: var(--font-mono);
            font-size: clamp(22px, 4vw, 36px);
            font-weight: 700;
            letter-spacing: -.01em;
            color: #fff;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 13px;
            color: var(--muted);
        }
        .meta-row span { font-family: var(--font-mono); color: var(--text); }

        .live-pill {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,229,255,.07);
            border: 1px solid rgba(0,229,255,.2);
            border-radius: 999px;
            padding: 8px 18px;
            font-size: 13px;
            font-family: var(--font-mono);
            color: var(--accent);
            white-space: nowrap;
        }

        /* ── KPI Grid ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
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
        .kpi:hover { border-color: var(--accent); transform: translateY(-2px); }
        .kpi::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(0,229,255,.04) 0%, transparent 60%);
            pointer-events: none;
        }

        .kpi-icon {
            font-size: 18px;
            margin-bottom: 10px;
            display: block;
        }
        .kpi-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            margin-bottom: 6px;
        }
        .kpi-value {
            font-family: var(--font-mono);
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            line-height: 1;
        }
        .kpi-unit {
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--muted);
            margin-top: 4px;
        }

        .kpi--voltage  { --c: #00e5ff; }
        .kpi--current  { --c: #7c3aed; }
        .kpi--power    { --c: #f59e0b; }
        .kpi--energy-c { --c: #10b981; }
        .kpi--energy-p { --c: #3b82f6; }
        .kpi--freq     { --c: #ec4899; }
        .kpi--pf       { --c: #f97316; }
        .kpi--ts       { --c: #94a3b8; }

        .kpi .kpi-value { color: var(--c, #fff); }
        .kpi:hover { border-color: var(--c, var(--accent)); }

        /* ── Charts ── */
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
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            margin-bottom: 20px;
        }
        .chart-wrap { position: relative; height: 220px; }

        /* ── Table ── */
        .section-head {
            font-family: var(--font-mono);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--muted);
            margin-bottom: 14px;
        }

        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        thead th {
            background: var(--surface2);
            padding: 14px 14px;
            text-align: left;
            font-family: var(--font-mono);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--surface2); }

        tbody td {
            padding: 12px 14px;
            font-family: var(--font-mono);
            font-size: 12px;
            color: var(--text);
        }
        tbody td:first-child { color: var(--muted); }

        /* highlight first row */
        tbody tr:first-child td { color: #fff; }
        tbody tr:first-child td:first-child { color: var(--accent); }
    </style>
</head>
<body>

<div class="shell">

    <!-- ── Header ── -->
    <header class="header">
        <div class="header-left">
            <div class="badge">
                <span class="badge-dot"></span>
                Meter Pilot — Live
            </div>
            <h1>{{ $device->name }}</h1>
            <div class="meta-row">
                <div>Topic <span>{{ $device->mqtt_topic }}</span></div>
                <div>Last Seen <span id="last_seen">{{ optional($device->last_seen_at)->toDateTimeString() ?? '—' }}</span></div>
            </div>
        </div>
        <div class="live-pill">
            <span class="badge-dot"></span>
            Real-time stream active
        </div>
    </header>

    <!-- ── KPI Cards ── -->
    <div class="kpi-grid">
        <div class="kpi kpi--voltage">
            <span class="kpi-icon">⚡</span>
            <div class="kpi-label">Voltage</div>
            <div class="kpi-value" id="voltage">{{ $device->latestState->voltage ?? '—' }}</div>
            <div class="kpi-unit">V</div>
        </div>
        <div class="kpi kpi--current">
            <span class="kpi-icon">〜</span>
            <div class="kpi-label">Current</div>
            <div class="kpi-value" id="current">{{ $device->latestState->current ?? '—' }}</div>
            <div class="kpi-unit">A</div>
        </div>
        <div class="kpi kpi--power">
            <span class="kpi-icon">◈</span>
            <div class="kpi-label">Power</div>
            <div class="kpi-value" id="power">{{ $device->latestState->power ?? '—' }}</div>
            <div class="kpi-unit">W</div>
        </div>
        <div class="kpi kpi--energy-c">
            <span class="kpi-icon">◉</span>
            <div class="kpi-label">Computed Energy</div>
            <div class="kpi-value" id="energy_computed_wh">{{ $device->latestState->energy_computed_wh ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>
        <div class="kpi kpi--energy-p">
            <span class="kpi-icon">◎</span>
            <div class="kpi-label">PZEM Energy</div>
            <div class="kpi-value" id="energy_pzem_wh">{{ $device->latestState->energy_pzem_wh ?? '—' }}</div>
            <div class="kpi-unit">Wh</div>
        </div>
        <div class="kpi kpi--freq">
            <span class="kpi-icon">≋</span>
            <div class="kpi-label">Frequency</div>
            <div class="kpi-value" id="frequency">{{ $device->latestState->frequency ?? '—' }}</div>
            <div class="kpi-unit">Hz</div>
        </div>
        <div class="kpi kpi--pf">
            <span class="kpi-icon">∿</span>
            <div class="kpi-label">Power Factor</div>
            <div class="kpi-value" id="pf">{{ $device->latestState->pf ?? '—' }}</div>
            <div class="kpi-unit">PF</div>
        </div>
        <div class="kpi kpi--ts">
            <span class="kpi-icon">◷</span>
            <div class="kpi-label">Last Reading</div>
            <div class="kpi-value" id="last_reading_at" style="font-size:13px;line-height:1.4">{{ optional($device->latestState->created_at)->format('d M Y') ?? '—' }}<br><span style="font-size:18px">{{ optional($device->latestState->created_at)->format('H:i:s') ?? '' }}</span></div>
        </div>
    </div>

    <!-- ── Charts ── -->
    <div class="charts-grid">

        <div class="chart-card chart-wide">
            <div class="chart-title">⚡ Voltage &amp; Current — Recent Readings</div>
            <div class="chart-wrap">
                <canvas id="chartVoltageCurrent"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">◈ Power (W)</div>
            <div class="chart-wrap">
                <canvas id="chartPower"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">◉ Energy Comparison (Wh)</div>
            <div class="chart-wrap">
                <canvas id="chartEnergy"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">≋ Frequency (Hz)</div>
            <div class="chart-wrap">
                <canvas id="chartFreq"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">∿ Power Factor</div>
            <div class="chart-wrap">
                <canvas id="chartPF"></canvas>
            </div>
        </div>

    </div>

    <!-- ── Recent Readings Table ── -->
    <div class="section-head">Recent Readings</div>
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
                @foreach ($recentReadings as $reading)
                    <tr>
                        <td>{{ optional($reading->created_at)->format('d M Y H:i:s') ?? '—' }}</td>
                        <td>{{ $reading->voltage }}</td>
                        <td>{{ $reading->current }}</td>
                        <td>{{ $reading->power }}</td>
                        <td>{{ $reading->energy_computed_wh }}</td>
                        <td>{{ $reading->energy_pzem_wh }}</td>
                        <td>{{ $reading->frequency }}</td>
                        <td>{{ $reading->pf }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

</div>

<script>
    // ── Extract data from Blade ──────────────────────────────────────────────
    const readings = @json($recentReadings->reverse()->values());

    const labels   = readings.map(r => {
        if (!r.created_at) return '';
        const d = new Date(r.created_at);
        return d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    });
    const voltages = readings.map(r => parseFloat(r.voltage)          || 0);
    const currents = readings.map(r => parseFloat(r.current)          || 0);
    const powers   = readings.map(r => parseFloat(r.power)            || 0);
    const energyC  = readings.map(r => parseFloat(r.energy_computed_wh)|| 0);
    const energyP  = readings.map(r => parseFloat(r.energy_pzem_wh)   || 0);
    const freqs    = readings.map(r => parseFloat(r.frequency)        || 0);
    const pfs      = readings.map(r => parseFloat(r.pf)               || 0);

    // ── Chart defaults ───────────────────────────────────────────────────────
    Chart.defaults.color         = '#64748b';
    Chart.defaults.borderColor   = '#1f2d45';
    Chart.defaults.font.family   = "'Space Mono', monospace";
    Chart.defaults.font.size     = 10;

    const baseOpts = {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 600, easing: 'easeInOutQuart' },
        plugins: {
            legend: { labels: { boxWidth: 10, padding: 16 } },
            tooltip: {
                backgroundColor: '#111827',
                borderColor: '#1f2d45',
                borderWidth: 1,
                padding: 10,
            }
        },
        scales: {
            x: {
                ticks: { maxTicksLimit: 8, maxRotation: 0, color: '#475569' },
                grid: { color: 'rgba(255,255,255,.04)' }
            },
            y: {
                grid: { color: 'rgba(255,255,255,.04)' },
                ticks: { color: '#475569' }
            }
        }
    };

    const area = (color) => ({
        fill: true,
        backgroundColor: `${color}18`,
        borderColor: color,
        borderWidth: 2,
        pointRadius: 0,
        pointHoverRadius: 4,
        tension: 0.4,
    });

    // ── Voltage & Current (dual-axis) ────────────────────────────────────────
    new Chart(document.getElementById('chartVoltageCurrent'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Voltage (V)', data: voltages, ...area('#00e5ff'), yAxisID: 'yV' },
                { label: 'Current (A)', data: currents, ...area('#7c3aed'), yAxisID: 'yC' },
            ]
        },
        options: {
            ...baseOpts,
            scales: {
                x: baseOpts.scales.x,
                yV: { position: 'left',  grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#00e5ff' } },
                yC: { position: 'right', grid: { drawOnChartArea: false },         ticks: { color: '#7c3aed' } },
            }
        }
    });

    // ── Power ────────────────────────────────────────────────────────────────
    new Chart(document.getElementById('chartPower'), {
        type: 'line',
        data: {
            labels,
            datasets: [{ label: 'Power (W)', data: powers, ...area('#f59e0b') }]
        },
        options: baseOpts
    });

    // ── Energy Comparison ────────────────────────────────────────────────────
    new Chart(document.getElementById('chartEnergy'), {
        type: 'line',
        data: {
            labels,
            datasets: [
                { label: 'Computed (Wh)', data: energyC, ...area('#10b981') },
                { label: 'PZEM (Wh)',     data: energyP, ...area('#3b82f6') },
            ]
        },
        options: baseOpts
    });

    // ── Frequency ────────────────────────────────────────────────────────────
    new Chart(document.getElementById('chartFreq'), {
        type: 'line',
        data: {
            labels,
            datasets: [{ label: 'Frequency (Hz)', data: freqs, ...area('#ec4899') }]
        },
        options: {
            ...baseOpts,
            scales: {
                ...baseOpts.scales,
                y: { ...baseOpts.scales.y, min: 45, max: 65 }
            }
        }
    });

    // ── Power Factor (bar) ───────────────────────────────────────────────────
    new Chart(document.getElementById('chartPF'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Power Factor',
                data: pfs,
                backgroundColor: pfs.map(v => v >= 0.9 ? '#10b98166' : v >= 0.7 ? '#f59e0b66' : '#ef444466'),
                borderColor:     pfs.map(v => v >= 0.9 ? '#10b981'   : v >= 0.7 ? '#f59e0b'   : '#ef4444'),
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            ...baseOpts,
            scales: {
                ...baseOpts.scales,
                y: { ...baseOpts.scales.y, min: 0, max: 1 }
            }
        }
    });
</script>

</body>
</html> --}}
