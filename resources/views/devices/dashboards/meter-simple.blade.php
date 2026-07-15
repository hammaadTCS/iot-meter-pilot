{{--
  SIMPLIFIED consumer meter dashboard — uses x-app-layout (sidebar shell).

  Served to every meter.access user WITHOUT the full dashboard
  (User::hasFullMeterDashboard() — meter.full_dashboard / meter.charts).
  Four KPI tiles (Voltage, Power, Monthly Units, Daily Units) and a collapsed
  "Usage History" section that expands on click and loads HOUR/DAY aggregate
  buckets — this view never requests raw minute-level readings, and the raw
  APIs would refuse it server-side anyway (403).

  Same design tokens, layout stack pattern and fetch/poll conventions as the
  full dashboard (devices/dashboards/meter.blade.php).
--}}
@php($deviceHealth = $device->healthSnapshot())
@php($deviceAvailability = $deviceAvailability ?? $device->availabilitySnapshot())
@php($deviceIssue = $deviceIssue ?? $device->issueSnapshot())
@php($currentSnapshotRecordedAt = data_get($currentSnapshot, 'recorded_at'))

@push('styles')
{{-- Chart.js — the expandable history section renders a units-per-bucket bar chart. --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
        /* Same design tokens as the full meter dashboard. */
        :root {
            --bg:        #0b0f1a;
            --surface:   #111827;
            --surface2:  #1a2235;
            --border:    #1f2d45;
            --accent:    #00e5ff;
            --accent2:   #7c3aed;
            --green:     #10b981;
            --amber:     #f59e0b;
            --red:       #ef4444;
            --text:      #e2e8f0;
            --muted:     #64748b;
            --font-mono: 'Space Mono', monospace;
            --font-body: 'DM Sans', sans-serif;
        }

        *, *::before, *::after { box-sizing: border-box; }

        .shell {
            position: relative;
            z-index: 1;
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 24px 80px;
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
        }
        .badge-dot {
            width: 7px; height: 7px; border-radius: 50%;
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

        .meta-row { display: flex; flex-wrap: wrap; gap: 20px; font-size: 13px; color: var(--muted); }
        .meta-row > div { display: inline-flex; align-items: center; gap: 8px; }
        .meta-row span { font-family: var(--font-mono); color: var(--text); }

        .health-pill {
            display: inline-flex; align-items: center;
            padding: 5px 10px; border-radius: 999px; border: 1px solid transparent;
            font: 700 10px/1 var(--font-mono);
            letter-spacing: .08em; text-transform: uppercase;
        }
        .health-pill--online  { color: var(--green); background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.25); }
        .health-pill--silent,
        .health-pill--stale   { color: #fcd34d; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.25); }
        .health-pill--offline,
        .health-pill--down    { color: #fca5a5; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.25); }
        .health-pill--never_seen,
        .health-pill--disabled,
        .health-pill--unknown { color: #cbd5e1; background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.25); }

        .header-right { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }

        .live-pill {
            display: flex; align-items: center; gap: 8px;
            background: rgba(0,229,255,.07);
            border: 1px solid rgba(0,229,255,.2);
            border-radius: 999px; padding: 8px 18px;
            font-size: 12px; font-family: var(--font-mono);
            color: var(--accent); white-space: nowrap;
        }
        #refreshCountdown { font-weight: 700; min-width: 20px; text-align: right; }

        .refresh-btn {
            font-family: var(--font-mono); font-size: 11px;
            padding: 6px 16px; border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface); color: var(--muted);
            cursor: pointer; transition: all .15s; letter-spacing: .05em;
        }
        .refresh-btn:hover { border-color: var(--accent); color: var(--accent); }

        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .header-link { display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }

        /* ── Status banners ── */
        .status-stack { display: grid; gap: 12px; margin: -12px 0 28px; }
        .status-banner {
            padding: 14px 16px; border-radius: 16px;
            border: 1px solid transparent; font-size: 14px; line-height: 1.5;
        }
        .status-banner.is-hidden { display: none; }
        .status-banner--online,
        .status-banner--ok,
        .status-banner--recovered { color: #d1fae5; background: rgba(16,185,129,.12); border-color: rgba(16,185,129,.25); }
        .status-banner--silent,
        .status-banner--stale     { color: #fde68a; background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.25); }
        .status-banner--offline,
        .status-banner--down,
        .status-banner--error     { color: #fecaca; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.25); }
        .status-banner--never_seen,
        .status-banner--disabled,
        .status-banner--unknown   { color: #cbd5e1; background: rgba(148,163,184,.12); border-color: rgba(148,163,184,.25); }

        /* ── KPI cards (the four consumer tiles) ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
            margin-bottom: 36px;
        }
        .kpi {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 20px 18px;
            position: relative; overflow: hidden;
            transition: border-color .2s, transform .2s;
        }
        .kpi:hover { border-color: var(--c, var(--accent)); transform: translateY(-2px); }
        .kpi::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,.025) 0%, transparent 60%);
            pointer-events: none;
        }
        .kpi-icon  { font-size: 18px; margin-bottom: 10px; display: block; }
        .kpi-label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em; color: var(--muted); margin-bottom: 6px; }
        .kpi-value { font-family: var(--font-mono); font-size: 26px; font-weight: 700; color: var(--c, #fff); line-height: 1; }
        .kpi-unit  { font-family: var(--font-mono); font-size: 12px; color: var(--muted); margin-top: 4px; }
        .kpi--voltage  { --c: #00e5ff; }
        .kpi--power    { --c: #f59e0b; }
        .kpi--energy-c { --c: #10b981; }
        .kpi--daily    { --c: #7c3aed; }

        /* ── Expandable history section ── */
        .expander {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
        }
        .expander-toggle {
            display: flex; align-items: center; justify-content: space-between;
            width: 100%; padding: 20px 24px;
            background: transparent; border: none; cursor: pointer;
            color: var(--text); text-align: left;
            transition: background .15s;
        }
        .expander-toggle:hover { background: var(--surface2); }
        .expander-title {
            font-family: var(--font-mono); font-size: 12px;
            text-transform: uppercase; letter-spacing: .12em; color: var(--muted);
        }
        .expander-hint { font-size: 12px; color: var(--muted); margin-top: 4px; font-family: var(--font-body); }
        .expander-chevron {
            font-family: var(--font-mono); font-size: 14px; color: var(--accent);
            transition: transform .2s; flex-shrink: 0; margin-left: 16px;
        }
        .expander.is-open .expander-chevron { transform: rotate(90deg); }
        .expander-body { display: none; padding: 0 24px 24px; border-top: 1px solid var(--border); }
        .expander.is-open .expander-body { display: block; }

        /* Range controls inside the expander */
        .range-bar { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin: 20px 0 14px; }
        .range-label {
            font-family: var(--font-mono); font-size: 10px;
            text-transform: uppercase; letter-spacing: .1em;
            color: var(--muted); margin-right: 6px;
        }
        .range-btn {
            font-family: var(--font-mono); font-size: 11px;
            padding: 6px 16px; border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--surface); color: var(--muted);
            cursor: pointer; transition: all .15s; letter-spacing: .06em;
        }
        .range-btn:hover { border-color: var(--accent); color: var(--accent); }
        .range-btn.active {
            background: rgba(0,229,255,.12);
            border-color: var(--accent); color: var(--accent);
            box-shadow: 0 0 12px rgba(0,229,255,.18);
        }

        .custom-range-fields { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .custom-range-field { display: flex; flex-direction: column; gap: 4px; }
        .custom-range-field-label {
            font-family: var(--font-mono); font-size: 9px;
            text-transform: uppercase; letter-spacing: .1em; color: var(--muted);
        }
        .custom-range-input {
            padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px;
            background: var(--bg); color: var(--text);
            font: 12px/1 var(--font-mono); cursor: pointer; color-scheme: dark;
            transition: border-color .15s, box-shadow .15s;
        }
        .custom-range-input:focus { outline: none; border-color: var(--accent2); box-shadow: 0 0 0 3px rgba(124,58,237,.15); }
        .custom-range-apply {
            font-family: var(--font-mono); font-size: 11px;
            padding: 8px 20px; border-radius: 8px;
            border: 1px solid var(--accent2);
            background: rgba(124,58,237,.12); color: #a78bfa;
            cursor: pointer; transition: all .15s; letter-spacing: .06em; white-space: nowrap;
        }
        .custom-range-apply:hover { background: rgba(124,58,237,.22); box-shadow: 0 0 12px rgba(124,58,237,.25); }
        .custom-range-clear {
            font-family: var(--font-mono); font-size: 10px;
            padding: 8px 14px; border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent; color: var(--muted);
            cursor: pointer; transition: all .15s;
        }
        .custom-range-clear:hover { border-color: var(--red); color: var(--red); }

        .history-total { font-family: var(--font-mono); font-size: 13px; color: var(--muted); margin-bottom: 14px; }
        .history-total span { color: var(--accent); font-weight: 600; }

        .section-wrap { position: relative; }
        .loading-overlay {
            position: absolute; inset: 0;
            background: rgba(11,15,26,.8);
            display: none; align-items: center; justify-content: center;
            z-index: 20; border-radius: 16px; backdrop-filter: blur(3px);
        }
        .loading-overlay.show { display: flex; }
        .spinner {
            width: 32px; height: 32px;
            border: 3px solid var(--border); border-top-color: var(--accent);
            border-radius: 50%; animation: spin .65s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .history-chart-wrap { position: relative; height: 240px; margin-bottom: 18px; }

        .history-table-wrap { max-height: 360px; overflow: auto; border: 1px solid var(--border); border-radius: 8px; }
        .history-table { width: 100%; border-collapse: collapse; font-family: var(--font-mono); font-size: 12px; }
        .history-table th {
            position: sticky; top: 0; background: var(--surface2); color: var(--muted);
            text-transform: uppercase; letter-spacing: .08em; font-size: 10px;
            padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border);
            white-space: nowrap; z-index: 2;
        }
        .history-table td { padding: 7px 12px; border-bottom: 1px solid var(--border); color: var(--text); white-space: nowrap; }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table td:first-child { color: var(--muted); }
        .history-empty { text-align: center; color: var(--muted); padding: 18px; font-family: var(--font-mono); font-size: 12px; }

        @media (max-width: 720px) {
            .header-right, .header-actions { width: 100%; align-items: stretch; justify-content: stretch; }
            .refresh-btn, .header-link { width: 100%; }
        }
</style>
@endpush

<x-app-layout>

<div class="shell">
    <header class="header">
        <div class="header-left">
            <div class="badge">
                <span class="badge-dot"></span>
                My Meter — Live
            </div>

            <h1>{{ $device->name }}</h1>

            <div class="meta-row">
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
            <div class="live-pill">
                <span class="badge-dot"></span>
                Auto-refresh in <span id="refreshCountdown">30</span>s
            </div>

            <div class="header-actions">
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
         THE FOUR CONSUMER KPI TILES (meter.live_data)
         Instantaneous tiles (Voltage/Power) zero out while the meter is
         DOWN; cumulative tiles (Monthly/Daily Units) keep their totals —
         the same rule as the full dashboard.
    ══════════════════════════════════════════════════════════ --}}
    @php($liveKpisDown = ($deviceHealth['status'] ?? '') === 'down')
    @if($canViewLiveData)
    <div class="kpi-grid">
        <div class="kpi kpi--voltage">
            <span class="kpi-icon">⚡</span>
            <div class="kpi-label">Voltage</div>
            <div class="kpi-value" id="kpi-voltage">{{ $liveKpisDown ? '0' : (data_get($currentSnapshot, 'voltage') ?? '—') }}</div>
            <div class="kpi-unit">V</div>
        </div>

        <div class="kpi kpi--power">
            <span class="kpi-icon">◈</span>
            <div class="kpi-label">Power</div>
            <div class="kpi-value" id="kpi-power">{{ $liveKpisDown ? '0' : (data_get($currentSnapshot, 'power') ?? '—') }}</div>
            <div class="kpi-unit">W</div>
        </div>

        {{-- Current calendar month's units, cached on the latest state. --}}
        <div class="kpi kpi--energy-c">
            <span class="kpi-icon">Σ</span>
            <div class="kpi-label">Monthly Units</div>
            <div class="kpi-value" id="kpi-monthly-units">{{ data_get($currentSnapshot, 'monthly_units_kwh') !== null ? number_format((float) data_get($currentSnapshot, 'monthly_units_kwh'), 3) : '—' }}</div>
            <div class="kpi-unit">kWh</div>
        </div>

        {{-- Today's units from the daily rollup; refreshed from the daily report API. --}}
        <div class="kpi kpi--daily">
            <span class="kpi-icon">☀</span>
            <div class="kpi-label">Daily Units</div>
            <div class="kpi-value" id="kpi-daily-units">{{ $todayUnits !== null ? number_format($todayUnits, 3) : '—' }}</div>
            <div class="kpi-unit">kWh today</div>
        </div>
    </div>
    @endif{{-- /meter.live_data --}}

    @if($canViewHistory)
    {{-- ══════════════════════════════════════════════════════════
         USAGE HISTORY (meter.history) — collapsed until clicked.
         Expanding lazily loads hour/day aggregate buckets from
         /readings/aggregate (units + avg voltage/power). Resolution is
         decided server-side: ≤48h windows are hourly, longer are daily.
    ══════════════════════════════════════════════════════════ --}}
    <div class="expander" id="historyExpander">
        <button class="expander-toggle" id="historyToggle" aria-expanded="false" aria-controls="historyBody">
            <span>
                <span class="expander-title">📈 Usage History &amp; Range</span>
                <span class="expander-hint" id="historyHint">Click to view your hourly and daily usage</span>
            </span>
            <span class="expander-chevron">▶</span>
        </button>

        <div class="expander-body" id="historyBody">
            <div class="range-bar">
                <span class="range-label">Range</span>
                <button class="range-btn active" data-range="today">Today</button>
                <button class="range-btn"        data-range="24h">24H</button>
                <button class="range-btn"        data-range="7d">7 Days</button>
                <button class="range-btn"        data-range="30d">30 Days</button>
            </div>

            {{-- Forgiving custom range: times are optional (default 12:00 AM);
                 leaving "To" empty means "up to now" and keeps the data live. --}}
            <div class="custom-range-fields">
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customFromDate">From date</label>
                    <input type="date" id="customFromDate" class="custom-range-input">
                </div>
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customFromTime">Time (optional, 12:00 AM)</label>
                    <input type="time" id="customFromTime" class="custom-range-input">
                </div>
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customToDate">To date (optional, till now)</label>
                    <input type="date" id="customToDate" class="custom-range-input">
                </div>
                <div class="custom-range-field">
                    <label class="custom-range-field-label" for="customToTime">Time (optional, 12:00 AM)</label>
                    <input type="time" id="customToTime" class="custom-range-input">
                </div>
                <button class="custom-range-apply" id="applyCustomRangeBtn">Apply</button>
                <button class="custom-range-clear" id="clearCustomRangeBtn" style="display:none;">✕ Clear</button>
            </div>

            <div class="history-total">
                Total for this range: <span id="historyTotal">—</span> kWh
                &nbsp;·&nbsp; resolution: <span id="historyBucketLabel">—</span>
            </div>

            <div class="section-wrap">
                <div class="loading-overlay" id="historyLoader">
                    <div class="spinner"></div>
                </div>

                <div class="history-chart-wrap">
                    <canvas id="chartHistory"></canvas>
                </div>

                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th style="text-align:right">Units (kWh)</th>
                                <th style="text-align:right">Avg Voltage (V)</th>
                                <th style="text-align:right">Avg Power (W)</th>
                            </tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr><td colspan="4" class="history-empty">Pick a range to load your usage.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif{{-- /meter.history --}}

</div>{{-- /.shell --}}

@push('scripts')
<script>
"use strict";

/* ═══════════════════════════════════════════════════════════════════════
 * 1. CONFIGURATION
 * Same endpoints/conventions as the full dashboard, minus every raw-reading
 * path (API_TABLE / API_CHART do not exist here on purpose).
 * ═══════════════════════════════════════════════════════════════════════ */

const DEVICE_ID        = {{ $device->id }};
const API_STATUS       = `/api/devices/${DEVICE_ID}/status`;
const API_AGGREGATE    = `/api/devices/${DEVICE_ID}/readings/aggregate`;
const API_CONSUMPTION  = `/api/devices/${DEVICE_ID}/readings/consumption`;
const API_DAILY        = `/api/devices/${DEVICE_ID}/consumption/daily`;
const REFRESH_INTERVAL = 30;  // seconds between background polls

/** Hybrid FGAC section flags (server-rendered; the APIs enforce the same slugs). */
const CAN_LIVE_DATA = @json($canViewLiveData);
const CAN_HISTORY   = @json($canViewHistory);

/** Blade-rendered snapshots used to seed the UI before the first fetch. */
const INITIAL_DEVICE_HEALTH       = @json($deviceHealth);
const INITIAL_DEVICE_AVAILABILITY = @json($deviceAvailability);
const INITIAL_DEVICE_ISSUE        = @json($deviceIssue);
const INITIAL_CURRENT_SNAPSHOT    = @json($currentSnapshot);


/* ═══════════════════════════════════════════════════════════════════════
 * 2. SHARED STATE
 * ═══════════════════════════════════════════════════════════════════════ */

let currentLastSeenAt        = INITIAL_DEVICE_HEALTH.last_seen_at;
let currentAvailabilityState = INITIAL_DEVICE_AVAILABILITY;
let currentIssueState        = INITIAL_DEVICE_ISSUE;
let currentSnapshot          = INITIAL_CURRENT_SNAPSHOT;

let autoRefreshTimer = null;
let countdownSeconds = REFRESH_INTERVAL;

/** History section state. */
let historyOpen     = false;   // expander expanded?
let historyLoaded   = false;   // first load done (lazy)?
let activeRange     = 'today'; // preset key, or 'custom'
let customRangeFrom = null;    // ISO strings when a custom window is applied
let customRangeTo   = null;


/* ═══════════════════════════════════════════════════════════════════════
 * 3. FORMATTING + HEALTH HELPERS
 * Compact copies of the full dashboard's helpers (same behaviour).
 * ═══════════════════════════════════════════════════════════════════════ */

function parseTimestamp(dateStr) {
    if (!dateStr) return null;
    const parsed = new Date(dateStr);
    return Number.isNaN(parsed.valueOf()) ? null : parsed;
}

/** Format an already-kWh value (3 dp), or — when absent. */
function formatKwh(kwh) {
    if (kwh === null || kwh === undefined || kwh === '') return '—';
    const n = Number(kwh);
    return Number.isFinite(n) ? n.toFixed(3) : '—';
}

function formatElapsedSeconds(totalSeconds) {
    if (totalSeconds < 60) return `${totalSeconds}s`;
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    if (minutes < 60) return seconds === 0 ? `${minutes}m` : `${minutes}m ${seconds}s`;
    const hours = Math.floor(minutes / 60);
    const remMin = minutes % 60;
    if (hours < 24) return remMin === 0 ? `${hours}h` : `${hours}h ${remMin}m`;
    const days = Math.floor(hours / 24);
    const remH = hours % 24;
    return remH === 0 ? `${days}d` : `${days}d ${remH}h`;
}

function computeDeviceHealthState() {
    const staleAfterSeconds = Number(INITIAL_DEVICE_HEALTH.stale_after_seconds ?? 180);
    const downAfterSeconds  = Number(INITIAL_DEVICE_HEALTH.down_after_seconds ?? 600);

    if (!INITIAL_DEVICE_HEALTH.is_enabled) {
        return { status: 'disabled', label: 'Disabled', message: 'Monitoring is disabled for this meter.' };
    }

    const parsed = parseTimestamp(currentLastSeenAt);
    if (!parsed) {
        return { status: 'never_seen', label: 'Never Seen', message: 'No telemetry has been received from this meter yet.' };
    }

    const age = Math.max(0, Math.floor((Date.now() - parsed.getTime()) / 1000));
    const ageText = formatElapsedSeconds(age);

    if (age >= downAfterSeconds) {
        return { status: 'down', label: 'Down', message: `Meter appears down. No telemetry has been received for ${ageText}.` };
    }
    if (age >= staleAfterSeconds) {
        return { status: 'stale', label: 'Stale', message: `Telemetry is delayed. Last reading was ${ageText} ago.` };
    }
    return { status: 'online', label: 'Online', message: `Meter is live. Telemetry was received ${ageText} ago.` };
}

function applyDeviceHealthState(healthState) {
    const labelEl = document.getElementById('deviceHealthLabel');
    const bannerEl = document.getElementById('deviceHealthBanner');

    if (labelEl) {
        labelEl.textContent = healthState.label;
        labelEl.className = `health-pill health-pill--${healthState.status}`;
    }
    if (!bannerEl) return;

    bannerEl.textContent = healthState.message;
    bannerEl.className = `status-banner status-banner--${healthState.status}`;
    if (healthState.status === 'online') bannerEl.classList.add('is-hidden');
}

function applyDeviceAvailabilityState(availabilityState) {
    const labelEl = document.getElementById('deviceAvailabilityLabel');
    const bannerEl = document.getElementById('deviceAvailabilityBanner');

    if (labelEl && availabilityState) {
        labelEl.textContent = availabilityState.label ?? 'Unknown';
        labelEl.className = `health-pill health-pill--${availabilityState.status ?? 'unknown'}`;
    }
    if (!bannerEl || !availabilityState) return;

    bannerEl.textContent = availabilityState.message ?? 'No MQTT availability message has been received for this meter yet.';
    bannerEl.className = `status-banner status-banner--${availabilityState.status ?? 'unknown'}`;
    if (availabilityState.status === 'online') bannerEl.classList.add('is-hidden');
}

function applyDeviceIssueState(issueState) {
    const bannerEl = document.getElementById('deviceIssueBanner');
    if (!bannerEl || !issueState) return;

    bannerEl.textContent = issueState.message ?? 'No active payload issues.';
    bannerEl.className = `status-banner status-banner--${issueState.status ?? 'ok'}`;
    if (!issueState.has_issue && issueState.status !== 'recovered') bannerEl.classList.add('is-hidden');
}

/** Tracks the zeroed/live rendering of the instantaneous tiles (down rule). */
let liveKpisZeroed = INITIAL_DEVICE_HEALTH.status === 'down';

function refreshDeviceHealth() {
    const healthState = computeDeviceHealthState();
    applyDeviceHealthState(healthState);

    const isDown = healthState.status === 'down';
    if (isDown !== liveKpisZeroed) {
        liveKpisZeroed = isDown;
        updateKPIs();
    }
}

function showConnectionIssue(message) {
    const banner = document.getElementById('connectionBanner');
    if (!banner) return;
    banner.textContent = message;
    banner.classList.remove('is-hidden');
}

function clearConnectionIssue() {
    const banner = document.getElementById('connectionBanner');
    if (!banner) return;
    banner.textContent = '';
    banner.classList.add('is-hidden');
}


/* ═══════════════════════════════════════════════════════════════════════
 * 4. KPI TILES
 * ═══════════════════════════════════════════════════════════════════════ */

function updateKPIs() {
    if (!CAN_LIVE_DATA) return;

    const snapshot = currentSnapshot;
    // Instantaneous tiles zero out while DOWN; cumulative tiles keep totals.
    const zeroLive = computeDeviceHealthState().status === 'down';

    document.getElementById('kpi-voltage').textContent       = zeroLive ? '0' : (snapshot?.voltage ?? '—');
    document.getElementById('kpi-power').textContent         = zeroLive ? '0' : (snapshot?.power   ?? '—');
    document.getElementById('kpi-monthly-units').textContent = formatKwh(snapshot?.monthly_units_kwh);
}

function renderDailyUnits(unitsKwh) {
    const el = document.getElementById('kpi-daily-units');
    if (el) el.textContent = formatKwh(unitsKwh);
}

function updateLastSeen(dateStr) {
    if (!dateStr) return;
    currentLastSeenAt = dateStr;

    const d = new Date(dateStr);
    document.getElementById('last_seen').textContent = Number.isNaN(d.valueOf())
        ? dateStr
        : d.toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        });

    refreshDeviceHealth();
}


/* ═══════════════════════════════════════════════════════════════════════
 * 5. FETCH HELPERS
 * ═══════════════════════════════════════════════════════════════════════ */

const FETCH_HEADERS = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function fetchDeviceStatus() {
    const response = await fetch(API_STATUS, { headers: FETCH_HEADERS });
    if (!response.ok) throw new Error(`Status API ${response.status}`);
    return response.json();
}

/**
 * Today's units from the daily report API (pre-aggregated rollup, ≤31 rows).
 * Returns null on failure so the tile keeps its last value.
 */
async function fetchTodayUnits() {
    try {
        const now = new Date();
        const month = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
        const r = await fetch(`${API_DAILY}?month=${month}`, { headers: FETCH_HEADERS });
        if (!r.ok) return null;
        const payload = await r.json();

        const dd = String(now.getDate()).padStart(2, '0');
        const today = `${month}-${dd}`;
        const row = (payload.days ?? []).find(d => d.date === today);
        return row ? row.units_kwh : 0;
    } catch (e) {
        return null;
    }
}

/**
 * Window query params shared by the aggregate + consumption fetches.
 * A custom window without an end is sent as from-only — the server treats
 * that as open-ended (up to now, live).
 */
function rangeParams() {
    if (activeRange === 'custom' && customRangeFrom) {
        return customRangeTo
            ? `from=${encodeURIComponent(customRangeFrom)}&to=${encodeURIComponent(customRangeTo)}`
            : `from=${encodeURIComponent(customRangeFrom)}`;
    }
    return `range=${activeRange}`;
}

async function fetchAggregate() {
    const response = await fetch(`${API_AGGREGATE}?${rangeParams()}`, { headers: FETCH_HEADERS });
    if (!response.ok) throw new Error(`Aggregate API ${response.status}`);
    return response.json();
}

/** Range total from the shared consumption service — reconciles with the KPIs. */
async function fetchRangeUnits() {
    try {
        const response = await fetch(`${API_CONSUMPTION}?${rangeParams()}`, { headers: FETCH_HEADERS });
        if (!response.ok) return null;
        return await response.json();
    } catch (err) {
        return null;
    }
}

function applyRuntimeStatus(statusPayload) {
    if (!statusPayload) return;

    if (statusPayload.availability) {
        currentAvailabilityState = statusPayload.availability;
        applyDeviceAvailabilityState(currentAvailabilityState);
    }

    if (statusPayload.health?.last_seen_at) {
        updateLastSeen(statusPayload.health.last_seen_at);
    } else {
        refreshDeviceHealth();
    }

    if (statusPayload.issue) {
        currentIssueState = statusPayload.issue;
        applyDeviceIssueState(currentIssueState);
    }

    if (statusPayload.current_snapshot) {
        currentSnapshot = statusPayload.current_snapshot;
        updateKPIs();
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 6. HISTORY SECTION (chart + table)
 * ═══════════════════════════════════════════════════════════════════════ */

@if($canViewHistory)
Chart.defaults.color       = '#64748b';
Chart.defaults.borderColor = '#1f2d45';
Chart.defaults.font.family = "'Space Mono', monospace";
Chart.defaults.font.size   = 10;

const chartHistory = new Chart(document.getElementById('chartHistory'), {
    type: 'bar',
    data: {
        labels: [],
        datasets: [{
            label:           'Units (kWh)',
            data:            [],
            backgroundColor: 'rgba(0,229,255,.35)',
            borderColor:     '#00e5ff',
            borderWidth:     1,
            borderRadius:    4,
        }],
    },
    options: {
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
            x: { ticks: { maxTicksLimit: 12, maxRotation: 0, color: '#475569' }, grid: { color: 'rgba(255,255,255,.04)' } },
            y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#475569' } },
        },
    },
});
@else
const chartHistory = null;
@endif

/**
 * Label a bucket for the chart/table.
 * Hour buckets → "14:00–15:00" (prefixed with the date when the window spans
 * more than one day); day buckets → "14 Jul 2026".
 */
function bucketLabel(bucket, period, multiDay) {
    if (bucket === 'day') {
        const d = new Date(`${period}T00:00:00`);
        return Number.isNaN(d.valueOf())
            ? String(period)
            : d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    const d = new Date(period.replace(' ', 'T'));
    if (Number.isNaN(d.valueOf())) return String(period);

    const hh = String(d.getHours()).padStart(2, '0');
    const next = String((d.getHours() + 1) % 24).padStart(2, '0');
    const hours = `${hh}:00–${next}:00`;

    return multiDay
        ? `${d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' })} ${hours}`
        : hours;
}

/** Render the history chart + table from an aggregate payload. */
function renderHistory(payload) {
    const bucketLabelEl = document.getElementById('historyBucketLabel');
    if (bucketLabelEl) bucketLabelEl.textContent = payload.bucket === 'hour' ? 'Hourly' : 'Daily';

    const buckets = Array.isArray(payload.buckets) ? payload.buckets : [];

    const multiDay = payload.bucket === 'hour'
        && new Set(buckets.map(b => String(b.period).slice(0, 10))).size > 1;

    const labels = buckets.map(b => bucketLabel(payload.bucket, b.period, multiDay));

    if (chartHistory) {
        chartHistory.data.labels           = labels;
        chartHistory.data.datasets[0].data = buckets.map(b => Number(b.units_kwh) || 0);
        chartHistory.update();
    }

    const body = document.getElementById('historyTableBody');
    if (!body) return;

    if (!buckets.length) {
        body.innerHTML = `<tr><td colspan="4" class="history-empty">No usage recorded for this range.</td></tr>`;
        return;
    }

    // Newest first in the table (the chart stays oldest → newest).
    body.innerHTML = buckets.slice().reverse().map((b, i) => `
        <tr>
            <td>${labels[buckets.length - 1 - i]}</td>
            <td style="text-align:right">${formatKwh(b.units_kwh)}</td>
            <td style="text-align:right">${b.avg_voltage ?? '—'}</td>
            <td style="text-align:right">${b.avg_power ?? '—'}</td>
        </tr>`).join('');
}

/** Load (or reload) the history section for the current range. */
async function loadHistory() {
    if (!CAN_HISTORY || !historyOpen) return;

    document.getElementById('historyLoader')?.classList.add('show');

    try {
        const [aggregate, rangeUnits] = await Promise.all([
            fetchAggregate(),
            fetchRangeUnits(),
        ]);

        renderHistory(aggregate);

        const totalEl = document.getElementById('historyTotal');
        if (totalEl) totalEl.textContent = rangeUnits ? formatKwh(rangeUnits.units_kwh) : '—';

        historyLoaded = true;
        clearConnectionIssue();
    } catch (err) {
        console.warn('[MeterSimple] loadHistory failed:', err);
        showConnectionIssue('Could not load usage history — please try again.');
        const body = document.getElementById('historyTableBody');
        if (body) body.innerHTML = `<tr><td colspan="4" class="history-empty">⚠ Failed to load data.</td></tr>`;
    } finally {
        document.getElementById('historyLoader')?.classList.remove('show');
    }
}


/* ═══════════════════════════════════════════════════════════════════════
 * 7. BACKGROUND REFRESH (30s poll)
 * Status + Daily Units always; the history section only while expanded on a
 * live preset range (a custom window is historical — no polling needed).
 * ═══════════════════════════════════════════════════════════════════════ */

async function backgroundRefresh() {
    try {
        const [statusPayload, todayUnits] = await Promise.all([
            CAN_LIVE_DATA ? fetchDeviceStatus() : Promise.resolve(null),
            CAN_LIVE_DATA ? fetchTodayUnits()   : Promise.resolve(null),
        ]);

        clearConnectionIssue();
        if (statusPayload) applyRuntimeStatus(statusPayload);
        if (todayUnits !== null) renderDailyUnits(todayUnits);

        // Presets and open-ended custom windows ("till now") are live —
        // keep them fresh. A closed from/to window is historical; skip it.
        if (historyOpen && (activeRange !== 'custom' || !customRangeTo)) {
            await loadHistory();
        }
    } catch (err) {
        console.warn('[MeterSimple] backgroundRefresh failed:', err);
        showConnectionIssue('Background refresh failed — meter health based on last successful timestamp.');
    }
}

function startAutoRefresh() {
    stopAutoRefresh();
    countdownSeconds = REFRESH_INTERVAL;
    updateCountdownDisplay();
    refreshDeviceHealth();

    autoRefreshTimer = setInterval(async () => {
        countdownSeconds--;
        updateCountdownDisplay();
        refreshDeviceHealth();

        if (countdownSeconds <= 0) {
            countdownSeconds = REFRESH_INTERVAL;
            await backgroundRefresh();
        }
    }, 1000);
}

function stopAutoRefresh() {
    if (autoRefreshTimer !== null) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }
}

function updateCountdownDisplay() {
    const el = document.getElementById('refreshCountdown');
    if (el) el.textContent = countdownSeconds;
}


/* ═══════════════════════════════════════════════════════════════════════
 * 8. EVENT WIRING
 * ═══════════════════════════════════════════════════════════════════════ */

// ── History expander: lazy-load on first open ─────────────────────────────
document.getElementById('historyToggle')?.addEventListener('click', async () => {
    const expander = document.getElementById('historyExpander');
    historyOpen = !historyOpen;
    expander.classList.toggle('is-open', historyOpen);
    document.getElementById('historyToggle').setAttribute('aria-expanded', String(historyOpen));

    const hint = document.getElementById('historyHint');
    if (hint) hint.textContent = historyOpen
        ? 'Hourly for short ranges, daily for longer ones'
        : 'Click to view your hourly and daily usage';

    if (historyOpen && !historyLoaded) {
        await loadHistory();
    }
});

// ── Preset range buttons ──────────────────────────────────────────────────
document.querySelectorAll('.range-btn[data-range]').forEach(btn => {
    btn.addEventListener('click', async () => {
        document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        customRangeFrom = null;
        customRangeTo   = null;
        const clearBtn = document.getElementById('clearCustomRangeBtn');
        if (clearBtn) clearBtn.style.display = 'none';

        activeRange = btn.dataset.range;
        await loadHistory();
    });
});

// ── Custom date/time range ────────────────────────────────────────────────
// Forgiving rules: a missing time defaults to 12:00 AM (midnight) on both
// fields; a missing "To" date means "up to now" and the window stays live.
document.getElementById('applyCustomRangeBtn')?.addEventListener('click', async () => {
    const fromDate = document.getElementById('customFromDate').value;
    const fromTime = document.getElementById('customFromTime').value || '00:00';
    const toDate   = document.getElementById('customToDate').value;
    const toTime   = document.getElementById('customToTime').value || '00:00';

    if (!fromDate) {
        alert('Please pick a start date.');
        return;
    }

    const from = new Date(`${fromDate}T${fromTime}`);
    const to   = toDate ? new Date(`${toDate}T${toTime}`) : null;

    if (to && from >= to) {
        alert('Start date/time must be before end date/time.');
        return;
    }

    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('clearCustomRangeBtn').style.display = '';

    customRangeFrom = from.toISOString();
    customRangeTo   = to ? to.toISOString() : null; // null → till now (live)
    activeRange     = 'custom';
    await loadHistory();
});

document.getElementById('clearCustomRangeBtn')?.addEventListener('click', async () => {
    document.getElementById('customFromDate').value = '';
    document.getElementById('customFromTime').value = '';
    document.getElementById('customToDate').value   = '';
    document.getElementById('customToTime').value   = '';
    document.getElementById('clearCustomRangeBtn').style.display = 'none';

    document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.range-btn[data-range="today"]').classList.add('active');

    customRangeFrom = null;
    customRangeTo   = null;
    activeRange     = 'today';
    await loadHistory();
});

// ── Manual refresh ────────────────────────────────────────────────────────
document.getElementById('manualRefreshBtn')?.addEventListener('click', async () => {
    await backgroundRefresh();
    startAutoRefresh();
});

// ── Realtime broadcast events (same window events as the full dashboard) ──
window.addEventListener('meter-reading-updated', (event) => {
    if (Number(event.detail?.device_id) !== Number(DEVICE_ID) || !event.detail?.reading) return;

    updateLastSeen(event.detail.last_seen_at ?? event.detail.reading.received_at);

    if (event.detail.latest_state_updated !== false) {
        currentSnapshot = {
            ...(currentSnapshot ?? {}),
            voltage: event.detail.reading.voltage ?? null,
            power: event.detail.reading.power ?? null,
            monthly_units_kwh: event.detail.monthly_units_kwh ?? currentSnapshot?.monthly_units_kwh ?? null,
            recorded_at: event.detail.last_seen_at ?? event.detail.reading.received_at ?? null,
        };
        updateKPIs();
    }
});

window.addEventListener('meter-availability-updated', (event) => {
    if (Number(event.detail?.device_id) !== Number(DEVICE_ID) || !event.detail?.availability) return;

    currentAvailabilityState = event.detail.availability;
    applyDeviceAvailabilityState(currentAvailabilityState);

    if (event.detail.health?.last_seen_at) {
        currentLastSeenAt = event.detail.health.last_seen_at;
        refreshDeviceHealth();
    }
});


/* ═══════════════════════════════════════════════════════════════════════
 * 9. BOOT
 * ═══════════════════════════════════════════════════════════════════════ */
(async () => {
    updateKPIs();
    applyDeviceAvailabilityState(currentAvailabilityState);
    refreshDeviceHealth();

    // Nothing dynamic granted — header pills stay on their server-rendered state.
    if (!CAN_LIVE_DATA && !CAN_HISTORY) return;

    await backgroundRefresh(); // first load: status + daily units
    startAutoRefresh();        // begin the 30-second poll cycle
})();
</script>
@endpush

</x-app-layout>
