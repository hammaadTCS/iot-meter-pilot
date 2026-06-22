{{--
  Meter device live dashboard — uses x-app-layout (sidebar shell).
  CSS and JS are pushed into the layout stacks so the layout controls font/meta loading.
--}}
@php($deviceHealth = $device->healthSnapshot())
@php($deviceAvailability = $deviceAvailability ?? $device->availabilitySnapshot())
@php($deviceIssue = $deviceIssue ?? $device->issueSnapshot())
@php($currentSnapshotRecordedAt = data_get($currentSnapshot, 'recorded_at'))

@push('styles')
{{-- Chart.js --}}
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

        *, *::before, *::after { box-sizing: border-box; }

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

        /* Vertical divider between range groups */
        .range-divider { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }

        /* ── Custom Range Card ── */
        .custom-range-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 20px;
            margin-bottom: 20px;
            transition: border-color .2s;
        }
        .custom-range-card.is-active {
            border-color: var(--accent2);
            box-shadow: 0 0 0 1px rgba(124,58,237,.2);
        }
        .custom-range-inner {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .custom-range-label {
            font-family: var(--font-mono);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: var(--muted);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .custom-range-fields {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
        }
        .custom-range-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .custom-range-field-label {
            font-family: var(--font-mono);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
        }
        .custom-range-input {
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--bg);
            color: var(--text);
            font: 12px/1 var(--font-mono);
            cursor: pointer;
            color-scheme: dark;
            transition: border-color .15s, box-shadow .15s;
        }
        .custom-range-input:focus {
            outline: none;
            border-color: var(--accent2);
            box-shadow: 0 0 0 3px rgba(124,58,237,.15);
        }
        .custom-range-apply {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 8px 20px;
            border-radius: 8px;
            border: 1px solid var(--accent2);
            background: rgba(124,58,237,.12);
            color: #a78bfa;
            cursor: pointer;
            transition: all .15s;
            letter-spacing: .06em;
            white-space: nowrap;
            align-self: flex-end;
        }
        .custom-range-apply:hover {
            background: rgba(124,58,237,.22);
            box-shadow: 0 0 12px rgba(124,58,237,.25);
        }
        .custom-range-clear {
            font-family: var(--font-mono);
            font-size: 10px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--muted);
            cursor: pointer;
            transition: all .15s;
            align-self: flex-end;
        }
        .custom-range-clear:hover { border-color: var(--red); color: var(--red); }

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

        /* ── Pagination bar ── */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 14px 0 4px;
        }

        .page-btn {
            font-family: var(--font-mono);
            font-size: 11px;
            padding: 6px 18px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            transition: all .15s;
            letter-spacing: .06em;
        }
        .page-btn:hover:not(:disabled) { border-color: var(--accent); color: var(--accent); }
        .page-btn:disabled { opacity: .35; cursor: not-allowed; }

        .page-info {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--muted);
            min-width: 80px;
            text-align: center;
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

/* ── Layout override: allow meter dashboard to use full width ── */
.shell {
    padding: 24px 0 80px;
    max-width: 100%;
}
</style>
@endpush

<x-app-layout>

{{-- Shell wrapper (keeps all existing meter-dashboard layout intact) --}}
<div class="shell">
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

                <a class="refresh-btn header-link" href="{{ route('devices.index') }}">
                    ← All Devices
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

        {{-- Units (kWh) consumed in the current calendar month. Maintained
             incrementally during ingestion (see meter_monthly_consumption) and
             cached on the latest state, so this card reads with no extra query. --}}
        <div class="kpi kpi--energy-c">
            <span class="kpi-icon">Σ</span>
            <div class="kpi-label">Monthly Units</div>
            <div class="kpi-value" id="kpi-monthly-units">{{ data_get($currentSnapshot, 'monthly_units_kwh') !== null ? number_format((float) data_get($currentSnapshot, 'monthly_units_kwh'), 3) : '—' }}</div>
            <div class="kpi-unit">kWh</div>
        </div>

        {{-- PZEM hardware energy counter, shown in kWh (units). Stored in Wh; the
             ÷1000 conversion is presentation-only — charts/history stay in Wh. --}}
        <div class="kpi kpi--energy-p">
            <span class="kpi-icon">◎</span>
            <div class="kpi-label">PZEM Energy</div>
            <div class="kpi-value" id="kpi-energy-p">{{ data_get($currentSnapshot, 'energy_pzem_wh') !== null ? number_format((float) data_get($currentSnapshot, 'energy_pzem_wh') / 1000, 3) : '—' }}</div>
            <div class="kpi-unit">kWh</div>
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

            <button class="range-btn active" data-range="1h">1H</button>
            <button class="range-btn"        data-range="6h">6H</button>
            <button class="range-btn"        data-range="24h">24H</button>
            <button class="range-btn"        data-range="today">Today</button>

            <div class="range-divider"></div>

            <button class="range-btn" data-range="7d">7 Days</button>
            <button class="range-btn" data-range="30d">30 Days</button>
            <button class="range-btn" data-range="all">Full Data</button>
        </div>

        <span class="new-badge" id="newBadge">— new readings</span>
    </div>

    {{-- ── Custom date/time range picker ──────────────────────────────────── --}}
    <div class="custom-range-card" id="customRangeCard">
        <div class="custom-range-inner">
            <span class="custom-range-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:5px"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Custom Range
            </span>
            <div class="custom-range-fields">
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customFrom">From</label>
                    <input type="datetime-local" id="customFrom" class="custom-range-input">
                </div>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--muted)" stroke-width="2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customTo">To</label>
                    <input type="datetime-local" id="customTo" class="custom-range-input">
                </div>
                <button class="custom-range-apply" id="applyCustomRangeBtn">Apply</button>
                <button class="custom-range-clear" id="clearCustomRangeBtn" style="display:none;">✕ Clear</button>
            </div>
        </div>
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

    {{-- ── Pagination bar ─────────────────────────────────────────────── --}}
    <div class="pagination-bar">
        <button class="page-btn" id="prevPageBtn" disabled>← Prev</button>
        <span class="page-info" id="pageInfo">—</span>
        <button class="page-btn" id="nextPageBtn" disabled>Next →</button>
    </div>

</div>{{-- /.shell --}}

</div>{{-- /.shell --}}

{{-- @push('scripts') MUST be inside <x-app-layout> so Blade's slot closure
     executes it before @stack('scripts') fires in app.blade.php.
     Placing it after </x-app-layout> means the stack has already been
     output by the time this push runs — the JS would never execute. --}}
@push('scripts')
<script>
"use strict";

/* ═══════════════════════════════════════════════════════════════════════
 * 1. CONFIGURATION
 * ═══════════════════════════════════════════════════════════════════════ */

const DEVICE_ID        = {{ $device->id }};
const API_TABLE        = `/api/devices/${DEVICE_ID}/readings`;
const API_CHART        = `/api/devices/${DEVICE_ID}/readings/chart`;
const API_STATUS       = `/api/devices/${DEVICE_ID}/status`;
const REFRESH_INTERVAL = 30;  // seconds between background polls
const TABLE_PER_PAGE   = 100; // must match DeviceReadingController::TABLE_PER_PAGE

/** Blade-rendered snapshots used to seed the UI before the first fetch. */
const INITIAL_DEVICE_HEALTH       = @json($deviceHealth);
const INITIAL_DEVICE_AVAILABILITY = @json($deviceAvailability);
const INITIAL_DEVICE_ISSUE        = @json($deviceIssue);
const INITIAL_CURRENT_SNAPSHOT    = @json($currentSnapshot);


/* ═══════════════════════════════════════════════════════════════════════
 * 2. SHARED STATE
 * ═══════════════════════════════════════════════════════════════════════ */

/** Active preset range key, or 'custom' when a date/time range is applied. */
let activeRange     = '1h';

/** ISO strings for the custom date/time range. Null = preset range is active. */
let customRangeFrom = null;
let customRangeTo   = null;

/**
 * Chart data — up to 500 evenly-sampled rows, oldest-first.
 * Drives all five Chart.js charts. Replaced entirely on range change.
 */
let chartReadings = [];

/**
 * Table data — exactly one page of raw rows, newest-first.
 * Only the current page is held in memory; navigating fetches fresh data.
 */
let tableReadings    = [];
let tablePage        = 1;
let tableTotal       = 0;
let tableTotalPages  = 1;

/**
 * After-cursor for the background-refresh poll.
 * Tracks the newest row the dashboard has seen so the poll only fetches deltas.
 */
let lastKnownId         = 0;
let lastKnownRecordedAt = null;

let autoRefreshTimer = null;
let countdownSeconds = REFRESH_INTERVAL;

/** KPI / health / availability / issue state — range-independent. */
let currentLastSeenAt        = INITIAL_DEVICE_HEALTH.last_seen_at;
let currentAvailabilityState = INITIAL_DEVICE_AVAILABILITY;
let currentIssueState        = INITIAL_DEVICE_ISSUE;
let currentSnapshot          = INITIAL_CURRENT_SNAPSHOT;


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
    if (range === 'all' || range === '30d')
        return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: '2-digit' });
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
    if (range === 'all' || range === '30d' || range === '7d')
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
        // Per-month rollup; usually absent on a single reading and filled in by
        // normalizeRealtimeSnapshot() / the /status snapshot. Defaults to null.
        monthly_units_kwh: reading.monthly_units_kwh ?? null,
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
    const response = await fetch(API_STATUS, {
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

/** Format a watt-hour counter as kWh (3 dp), or — when absent. */
function whToKwh(wh) {
    if (wh === null || wh === undefined || wh === '') return '—';
    const n = Number(wh);
    return Number.isFinite(n) ? (n / 1000).toFixed(3) : '—';
}

/** Format an already-kWh value (3 dp), or — when absent. */
function formatKwh(kwh) {
    if (kwh === null || kwh === undefined || kwh === '') return '—';
    const n = Number(kwh);
    return Number.isFinite(n) ? n.toFixed(3) : '—';
}

/**
 * Updates the 8 live KPI cards with the current snapshot values.
 */
function updateKPIs() {
    const snapshot = currentSnapshot;
    const timestampParts = formatTimestampParts(snapshot?.recorded_at);

    document.getElementById('kpi-voltage').textContent       = snapshot?.voltage   ?? '—';
    document.getElementById('kpi-current').textContent       = snapshot?.current   ?? '—';
    document.getElementById('kpi-power').textContent         = snapshot?.power     ?? '—';
    document.getElementById('kpi-monthly-units').textContent = formatKwh(snapshot?.monthly_units_kwh);
    document.getElementById('kpi-energy-p').textContent      = whToKwh(snapshot?.energy_pzem_wh);
    document.getElementById('kpi-freq').textContent          = snapshot?.frequency ?? '—';
    document.getElementById('kpi-pf').textContent            = snapshot?.pf        ?? '—';
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

    const snapshot = makeSnapshotFromReading(
        eventPayload.reading,
        eventPayload.last_seen_at ?? eventPayload.reading.received_at ?? eventPayload.reading.created_at ?? null,
    );

    if (snapshot) {
        // Monthly units rides at the top level of the broadcast (it is a
        // per-month rollup, not a property of this single reading).
        snapshot.monthly_units_kwh = eventPayload.monthly_units_kwh ?? null;
    }

    return snapshot;
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
        case '30d':
            return createdAt >= new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
        case 'all':
            return true;   // every reading belongs in the full-history view
        default:
            return true;
    }
}

/**
 * Ingest a realtime WebSocket reading without waiting for the next poll.
 * Updates both the chart (append to end) and the table (prepend if on page 1).
 *
 * @param {object} eventPayload
 */
function ingestRealtimeReading(eventPayload) {
    const reading = normalizeRealtimeReading(eventPayload);
    if (!reading) return;

    updateLastSeen(eventPayload.last_seen_at ?? eventPayload.reading.received_at);

    if (eventPayload.latest_state_updated !== false) {
        updateCurrentSnapshot(normalizeRealtimeSnapshot(eventPayload));
    }

    clearActiveIssueState(eventPayload.last_seen_at ?? eventPayload.reading.received_at);
    restoreAvailabilityFromTelemetry();

    if (!readingFallsInRange(reading, activeRange)) return;

    // ── Chart: append the new reading to the sampled set ──────────────────
    const existingChartIdx = chartReadings.findIndex(r => Number(r.id) === reading.id);
    if (existingChartIdx !== -1) {
        chartReadings[existingChartIdx] = { ...chartReadings[existingChartIdx], ...reading };
    } else {
        chartReadings = [...chartReadings, reading]; // append; oldest-first order preserved
    }
    updateCharts(chartReadings, activeRange);

    // ── Table: update based on which page is visible ───────────────────────
    if (tablePage === 1) {
        const existingTableIdx = tableReadings.findIndex(r => Number(r.id) === reading.id);
        if (existingTableIdx !== -1) {
            // Update existing row in-place.
            tableReadings[existingTableIdx] = { ...tableReadings[existingTableIdx], ...reading };
            updateTable(activeRange, 0);
        } else {
            // Prepend new row; keep the page at TABLE_PER_PAGE rows.
            tableReadings   = [reading, ...tableReadings].slice(0, TABLE_PER_PAGE);
            tableTotal     += 1;
            tableTotalPages = Math.max(1, Math.ceil(tableTotal / TABLE_PER_PAGE));
            updateTable(activeRange, 1);
            renderPagination();
            showNewBadge(1);
        }
    } else {
        // Not on page 1 — update the total count only.
        tableTotal     += 1;
        tableTotalPages = Math.max(1, Math.ceil(tableTotal / TABLE_PER_PAGE));
        renderPagination();
        showNewBadge(1);
    }

    updateKPIs();
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
 * 7. TABLE + PAGINATION
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Render the current page of tableReadings into the DOM.
 * Rows are newest-first; the first newRowCount rows receive the flash animation.
 *
 * @param {string} range       — active range key (for date formatting)
 * @param {number} newRowCount — leading rows to flash (0 = no flash)
 */
function updateTable(range, newRowCount = 0) {
    const tbody = document.getElementById('readings-body');

    if (!tableReadings.length) {
        tbody.innerHTML = `<tr><td colspan="8">
            <div class="empty-state">No readings found for this time range.</div>
        </td></tr>`;
        return;
    }

    tbody.innerHTML = tableReadings.map((r, i) => {
        const flashClass = (newRowCount > 0 && i < newRowCount) ? 'row-new' : '';
        return `
        <tr class="${flashClass}">
            <td>${fmtTableCell(r.created_at, range)}</td>
            <td>${r.voltage            ?? '—'}</td>
            <td>${r.current            ?? '—'}</td>
            <td>${r.power              ?? '—'}</td>
            <td>${r.energy_computed_wh ?? '—'}</td>
            <td>${r.energy_pzem_wh     ?? '—'}</td>
            <td>${r.frequency          ?? '—'}</td>
            <td>${r.pf                 ?? '—'}</td>
        </tr>`;
    }).join('');
}

/**
 * Sync the Prev/Next buttons and the "X–Y of Z · range" info line.
 * Called after any operation that changes tablePage, tableTotal, or activeRange.
 */
function renderPagination() {
    const prevBtn  = document.getElementById('prevPageBtn');
    const nextBtn  = document.getElementById('nextPageBtn');
    const pageInfo = document.getElementById('pageInfo');
    const rowCount = document.getElementById('rowCount');
    const rangeLabel = document.getElementById('rangeLabel');

    if (prevBtn) prevBtn.disabled = tablePage <= 1;
    if (nextBtn) nextBtn.disabled = tablePage >= tableTotalPages;

    const from = tableTotal === 0 ? 0 : (tablePage - 1) * TABLE_PER_PAGE + 1;
    const to   = Math.min(tablePage * TABLE_PER_PAGE, tableTotal);

    if (pageInfo) {
        pageInfo.textContent = tableTotal > 0
            ? `Page ${tablePage} of ${tableTotalPages}`
            : 'No data';
    }

    if (rowCount) {
        rowCount.textContent = tableTotal > 0
            ? `${from.toLocaleString()}–${to.toLocaleString()} of ${tableTotal.toLocaleString()}`
            : '—';
    }

    if (rangeLabel) {
        rangeLabel.textContent = (activeRange === 'custom' && customRangeFrom && customRangeTo)
            ? `${new Date(customRangeFrom).toLocaleString('en-GB')} → ${new Date(customRangeTo).toLocaleString('en-GB')}`
            : activeRange.toUpperCase();
    }
}

/**
 * Navigate to a specific table page.
 * Fetches the page from the server, replaces tableReadings, and re-renders.
 *
 * @param {number} page
 */
async function goToPage(page) {
    if (page < 1 || page > tableTotalPages) return;

    document.getElementById('tableLoader').classList.add('show');

    try {
        const { rows, meta } = await fetchTableData(page);
        tableReadings   = rows;
        tablePage       = meta.current_page;
        tableTotal      = meta.total;
        tableTotalPages = meta.last_page;
        updateTable(activeRange, 0);
        renderPagination();
    } catch (err) {
        console.warn('[MeterDash] goToPage failed:', err);
        showConnectionIssue('Could not load page — please try again.');
    } finally {
        document.getElementById('tableLoader').classList.remove('show');
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 8. API FETCH HELPERS
 * Three focused functions — one per data path.
 * ═══════════════════════════════════════════════════════════════════════ */

/**
 * Build the range/custom-range portion of a query string.
 * Reads from shared state so callers don't need to pass it explicitly.
 */
function rangeParams() {
    if (customRangeFrom && customRangeTo) {
        return `from=${encodeURIComponent(customRangeFrom)}&to=${encodeURIComponent(customRangeTo)}`;
    }
    return `range=${activeRange}`;
}

/**
 * Fetch chart data — up to 500 evenly-sampled rows, oldest-first.
 * The backend handles the sampling so this always returns a small payload
 * regardless of how many raw rows exist in the selected range.
 *
 * @returns {Promise<Array>}
 */
async function fetchChartData() {
    const response = await fetch(`${API_CHART}?${rangeParams()}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`Chart API ${response.status}`);
    const json = await response.json();
    return Array.isArray(json) ? json : [];
}

/**
 * Fetch one page of raw table data, newest-first.
 *
 * @param {number} page
 * @returns {Promise<{ rows: Array, meta: object }>}
 */
async function fetchTableData(page = 1) {
    const response = await fetch(`${API_TABLE}?${rangeParams()}&page=${page}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`Table API ${response.status}`);
    const json = await response.json();
    return {
        rows: Array.isArray(json.data) ? json.data : [],
        meta: json.meta ?? { current_page: 1, last_page: 1, per_page: TABLE_PER_PAGE, total: 0 },
    };
}

/**
 * Fetch only rows newer than the last-known cursor (background refresh).
 * Returns a plain oldest-first array — matches the backend's refresh path.
 *
 * @returns {Promise<Array>}
 */
async function fetchNewRows() {
    let url = `${API_TABLE}?${rangeParams()}`;
    if (lastKnownRecordedAt) {
        url += `&after_received_at=${encodeURIComponent(lastKnownRecordedAt)}&after_id=${lastKnownId}`;
    } else if (lastKnownId > 0) {
        url += `&after_id=${lastKnownId}`;
    }
    const response = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) throw new Error(`Refresh API ${response.status}`);
    const json = await response.json();
    return Array.isArray(json) ? json : [];
}


/* ═══════════════════════════════════════════════════════════════════════
 * 9. FULL LOAD
 * Fetches chart data and table page 1 in parallel. Fast at any range size
 * because the chart endpoint always returns ≤500 rows and the table returns
 * exactly one page of 100 rows.
 * ═══════════════════════════════════════════════════════════════════════ */

async function fullLoad(range) {
    document.getElementById('chartsLoader').classList.add('show');
    document.getElementById('tableLoader').classList.add('show');

    try {
        // Three parallel requests — chart sample, table page 1, device status.
        const [chartRows, { rows: tableRows, meta }, statusPayload] = await Promise.all([
            fetchChartData(),
            fetchTableData(1),
            fetchDeviceStatus(),
        ]);

        // Chart — oldest-first, ready for Chart.js.
        chartReadings = chartRows;
        updateCharts(chartReadings, range);

        // Table — newest-first, one page only.
        tableReadings   = tableRows;
        tablePage       = meta.current_page;
        tableTotal      = meta.total;
        tableTotalPages = meta.last_page;
        updateTable(range, 0);
        renderPagination();

        // Seed the background-refresh cursor from the newest row on page 1.
        if (tableRows.length > 0) {
            lastKnownId         = Number(tableRows[0].id ?? 0);
            lastKnownRecordedAt = tableRows[0].created_at ?? null;
        } else {
            lastKnownId         = 0;
            lastKnownRecordedAt = null;
        }

        clearConnectionIssue();
        applyRuntimeStatus(statusPayload);

        if (!currentSnapshot) {
            updateCurrentSnapshot(makeSnapshotFromReading(tableRows[0] ?? null));
        }

    } catch (err) {
        console.error('[MeterDash] fullLoad failed:', err);
        showConnectionIssue('Dashboard connection issue — readings could not be loaded.');
        document.getElementById('readings-body').innerHTML =
            `<tr><td colspan="8"><div class="empty-state">⚠ Failed to load data.</div></td></tr>`;
    } finally {
        document.getElementById('chartsLoader').classList.remove('show');
        document.getElementById('tableLoader').classList.remove('show');
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 10. BACKGROUND / SILENT REFRESH
 * Runs every REFRESH_INTERVAL seconds. Fetches new rows via the after-cursor
 * and re-fetches the chart sample so both stay current.
 * ═══════════════════════════════════════════════════════════════════════ */

async function backgroundRefresh() {
    try {
        // Parallel: delta rows for the table + refreshed chart sample + device status.
        const [newRows, freshChart, statusPayload] = await Promise.all([
            fetchNewRows(),
            fetchChartData(),
            fetchDeviceStatus(),
        ]);

        clearConnectionIssue();
        applyRuntimeStatus(statusPayload);

        // Always update the chart — the sampled set shifts as time moves forward.
        chartReadings = freshChart;
        updateCharts(chartReadings, activeRange);

        if (!newRows.length) return;

        // Advance the cursor to the newest row returned.
        const newestRow = newRows[newRows.length - 1];
        lastKnownId         = Number(newestRow?.id ?? lastKnownId);
        lastKnownRecordedAt = newestRow?.created_at ?? lastKnownRecordedAt;

        if (newestRow?.created_at) updateLastSeen(newestRow.created_at);

        if (tablePage === 1) {
            // Page 1 shows the newest rows — prepend the new arrivals.
            const newNewestFirst = [...newRows].sort(compareReadingsNewestFirst);
            tableReadings = [...newNewestFirst, ...tableReadings].slice(0, TABLE_PER_PAGE);
            tableTotal   += newRows.length;
            tableTotalPages = Math.max(1, Math.ceil(tableTotal / TABLE_PER_PAGE));
            updateTable(activeRange, newNewestFirst.length);
            renderPagination();
        } else {
            // Deeper pages: update the total count but don't disturb the visible rows.
            tableTotal  += newRows.length;
            tableTotalPages = Math.max(1, Math.ceil(tableTotal / TABLE_PER_PAGE));
            renderPagination();
        }

        showNewBadge(newRows.length);

    } catch (err) {
        console.warn('[MeterDash] backgroundRefresh failed:', err);
        showConnectionIssue('Background refresh failed — meter health based on last successful timestamp.');
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
/** Reset all range/chart/table state before loading a new window. */
function resetState() {
    chartReadings       = [];
    tableReadings       = [];
    tablePage           = 1;
    tableTotal          = 0;
    tableTotalPages     = 1;
    lastKnownId         = 0;
    lastKnownRecordedAt = null;
}

// ── Preset range buttons ──────────────────────────────────────────────────
document.querySelectorAll('.range-btn[data-range]').forEach(btn => {
    btn.addEventListener('click', async () => {
        document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        customRangeFrom = null;
        customRangeTo   = null;
        document.getElementById('customRangeCard').classList.remove('is-active');
        document.getElementById('clearCustomRangeBtn').style.display = 'none';

        activeRange = btn.dataset.range;
        resetState();

        await fullLoad(activeRange);
        startAutoRefresh();
    });
});

// ── Custom date/time range ────────────────────────────────────────────────
document.getElementById('applyCustomRangeBtn').addEventListener('click', async () => {
    const fromVal = document.getElementById('customFrom').value;
    const toVal   = document.getElementById('customTo').value;

    if (!fromVal || !toVal) {
        alert('Please select both a start and end date/time.');
        return;
    }
    if (new Date(fromVal) >= new Date(toVal)) {
        alert('Start date/time must be before end date/time.');
        return;
    }

    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('customRangeCard').classList.add('is-active');
    document.getElementById('clearCustomRangeBtn').style.display = '';

    customRangeFrom = new Date(fromVal).toISOString();
    customRangeTo   = new Date(toVal).toISOString();
    activeRange     = 'custom';
    resetState();

    await fullLoad('custom');
    stopAutoRefresh(); // historical window — no live polling needed
});

document.getElementById('clearCustomRangeBtn').addEventListener('click', async () => {
    document.getElementById('customFrom').value = '';
    document.getElementById('customTo').value   = '';
    document.getElementById('customRangeCard').classList.remove('is-active');
    document.getElementById('clearCustomRangeBtn').style.display = 'none';

    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.range-btn[data-range="1h"]').classList.add('active');

    customRangeFrom = null;
    customRangeTo   = null;
    activeRange     = '1h';
    resetState();

    await fullLoad('1h');
    startAutoRefresh();
});

// ── Table pagination ──────────────────────────────────────────────────────
document.getElementById('prevPageBtn')?.addEventListener('click', () => goToPage(tablePage - 1));
document.getElementById('nextPageBtn')?.addEventListener('click', () => goToPage(tablePage + 1));

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
    await fullLoad(activeRange); // first load: chart sample + table page 1 in parallel
    startAutoRefresh();          // begin the 30-second background poll cycle
})();
</script>

@endpush

</x-app-layout>
