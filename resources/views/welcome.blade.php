<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>SmartHome IoT Platform — Intelligent Device Monitoring</title>
    <meta name="description" content="Monitor, manage and automate your entire smart home from one unified IoT platform. Real-time data, role-based access, and enterprise-grade MQTT pipeline.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* ──────────────────────────────────────────────────────
           RESET & BASE
        ────────────────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            overflow-x: hidden;          /* prevent any horizontal bleed */
            scroll-behavior: smooth;
        }

        body {
            background: #0b0f1a;
            color: #e2e8f0;
            font-family: 'DM Sans', sans-serif;
            line-height: 1.6;
        }

        :root {
            --accent:   #00e5ff;
            --accent2:  #7c3aed;
            --surface:  #111827;
            --surface2: #1a2235;
            --border:   #1f2d45;
            --muted:    #64748b;
            --navbar-h: 64px;
        }

        /* ──────────────────────────────────────────────────────
           BACKGROUND — fixed, behind everything, z-index: 0
        ────────────────────────────────────────────────────── */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            /* dot grid */
            background-image: radial-gradient(circle, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 30px 30px;
        }

        .bg-glow {
            position: fixed;
            top: -10%;
            left: 50%;
            transform: translateX(-50%);
            width: 900px;
            height: 700px;
            background: radial-gradient(ellipse at center,
                rgba(0,229,255,0.09) 0%,
                rgba(124,58,237,0.05) 40%,
                transparent 70%);
            filter: blur(60px);
            pointer-events: none;
            z-index: 0;
        }

        /* all page sections sit above background */
        nav, main, footer { position: relative; z-index: 1; }

        /* ──────────────────────────────────────────────────────
           NAVBAR — fixed at top, full width
        ────────────────────────────────────────────────────── */
        .navbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 100;
            height: var(--navbar-h);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            background: rgba(11,15,26,0.88);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(31,45,69,0.55);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .pulse-dot {
            flex-shrink: 0;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 10px var(--accent);
            animation: pulse 2.2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1;   transform: scale(1);    box-shadow: 0 0 10px var(--accent); }
            50%       { opacity: 0.4; transform: scale(0.75); box-shadow: 0 0 4px  var(--accent); }
        }

        .brand-name {
            font-family: 'Space Mono', monospace;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .18em;
            color: #fff;
            text-transform: uppercase;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 500;
            text-decoration: none;
            transition: background .15s, color .15s, box-shadow .15s;
            white-space: nowrap;
        }

        .nav-ghost {
            color: #94a3b8;
            border: 1px solid transparent;
        }
        .nav-ghost:hover { color: #fff; background: rgba(31,45,69,.55); }

        .nav-primary {
            color: #0b0f1a;
            background: var(--accent);
            border: 1px solid var(--accent);
            font-weight: 600;
        }
        .nav-primary:hover {
            background: #00cfeb;
            box-shadow: 0 0 18px rgba(0,229,255,.35);
        }

        /* ──────────────────────────────────────────────────────
           MAIN wrapper — clears the fixed navbar
        ────────────────────────────────────────────────────── */
        main { padding-top: var(--navbar-h); }

        /* ──────────────────────────────────────────────────────
           LIVE TICKER
           Structure: .ticker-wrap > .ticker-label + .ticker-scroll > .ticker-track
           The label is a fixed-width flex item that never moves.
           .ticker-scroll takes all remaining width and clips with overflow:hidden.
           The animated .ticker-track lives ONLY inside .ticker-scroll,
           so it can never bleed over or behind the "Live" badge.
        ────────────────────────────────────────────────────── */
        .ticker-wrap {
            width: 100%;
            display: flex;
            align-items: stretch;
            height: 52px;                              /* taller ticker */
            background: rgba(0,229,255,.04);
            border-bottom: 1px solid rgba(0,229,255,.12);
        }

        /* fixed "LIVE" badge — never moves, never scrolls */
        .ticker-label {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 22px;
            font-family: 'Space Mono', monospace;
            font-size: 11px;
            letter-spacing: .14em;
            color: var(--accent);
            text-transform: uppercase;
            background: rgba(0,229,255,.07);
            border-right: 1px solid rgba(31,45,69,.8);
            white-space: nowrap;
            z-index: 2;                                /* stays above the track */
        }

        .ticker-label .pulse-dot { width: 6px; height: 6px; flex-shrink: 0; }

        /* scrollable viewport — clips exactly what the track overflows */
        .ticker-scroll {
            flex: 1;
            overflow: hidden;                          /* ← the actual clip boundary */
            display: flex;
            align-items: center;
            position: relative;
        }

        /* the moving strip — lives INSIDE .ticker-scroll only */
        .ticker-track {
            display: flex;
            align-items: center;
            white-space: nowrap;
            animation: ticker-move 32s linear infinite;
            will-change: transform;
        }

        .ticker-wrap:hover .ticker-track { animation-play-state: paused; }

        @keyframes ticker-move {
            0%   { transform: translateX(0); }
            100% { transform: translateX(-50%); }
        }

        .ticker-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 0 32px;
            font-family: 'Space Mono', monospace;
            font-size: 13px;                           /* bigger text */
            color: #475569;
            letter-spacing: .05em;
            border-right: 1px solid rgba(31,45,69,.6);
            white-space: nowrap;
            line-height: 1;
        }

        .ticker-item .tv {
            color: #c0cde0;
            font-weight: 700;
        }


        /* ──────────────────────────────────────────────────────
           HERO
        ────────────────────────────────────────────────────── */
        .hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            /* viewport height minus navbar (64px) and ticker (52px) */
            min-height: calc(100vh - var(--navbar-h) - 52px);
            padding: 64px 24px 56px;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 5px 16px;
            border-radius: 999px;
            background: rgba(0,229,255,.07);
            border: 1px solid rgba(0,229,255,.22);
            font-family: 'Space Mono', monospace;
            font-size: 10.5px;
            letter-spacing: .12em;
            color: var(--accent);
            text-transform: uppercase;
            margin-bottom: 28px;
        }

        .hero-title {
            font-family: 'Space Mono', monospace;
            font-size: clamp(32px, 6.5vw, 72px);
            font-weight: 700;
            line-height: 1.07;
            letter-spacing: -.02em;
            color: #fff;
            max-width: 860px;
            margin-bottom: 20px;
        }

        .hero-title .c1 { color: var(--accent); }
        .hero-title .c2 { color: var(--accent2); }

        .hero-sub {
            font-size: clamp(15px, 2vw, 18px);
            color: var(--muted);
            max-width: 560px;
            line-height: 1.75;
            margin-bottom: 40px;
        }

        .hero-cta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin-bottom: 56px;
        }

        .btn-xl {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 13px 30px;
            border-radius: 13px;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            transition: transform .18s, box-shadow .18s, background .15s;
        }

        .btn-xl:hover { transform: translateY(-2px); }

        .btn-filled {
            background: var(--accent);
            color: #0b0f1a;
            border: 1px solid var(--accent);
        }
        .btn-filled:hover { background: #00cfe9; box-shadow: 0 8px 28px rgba(0,229,255,.28); }

        .btn-ghost-xl {
            background: transparent;
            color: #e2e8f0;
            border: 1px solid var(--border);
        }
        .btn-ghost-xl:hover { background: rgba(31,45,69,.5); }

        /* ── orbit visual ── */
        .orbit-wrap {
            position: relative;
            width: 300px; height: 300px;
            flex-shrink: 0;
        }

        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(31,45,69,.7);
        }

        .ring-outer { inset: 0;   animation: spin 28s linear infinite; }
        .ring-inner { inset: 44px; border-color: rgba(0,229,255,.15); animation: spin 18s linear infinite reverse; }

        @keyframes spin { to { transform: rotate(360deg); } }

        .orbit-core {
            position: absolute;
            inset: 88px;
            border-radius: 50%;
            background: radial-gradient(circle at 38% 38%, rgba(0,229,255,.14), rgba(124,58,237,.09), transparent 70%);
            border: 1px solid rgba(0,229,255,.2);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
        }

        .orbit-core-icon { font-size: 30px; filter: drop-shadow(0 0 10px var(--accent)); }
        .orbit-core-lbl  { font-family: 'Space Mono', monospace; font-size: 8px; letter-spacing: .14em; color: var(--accent); text-transform: uppercase; }

        .orbit-chip {
            position: absolute;
            width: 42px; height: 42px;
            border-radius: 11px;
            background: var(--surface);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            box-shadow: 0 4px 18px rgba(0,0,0,.4);
        }

        /* positions on the 4 cardinal points */
        .oc-top    { top: 0;   left: 50%; transform: translateX(-50%); }
        .oc-right  { right: 0; top: 50%;  transform: translateY(-50%); }
        .oc-bottom { bottom:0; left: 50%; transform: translateX(-50%); }
        .oc-left   { left: 0;  top: 50%;  transform: translateY(-50%); }

        /* ──────────────────────────────────────────────────────
           STATS BAR
        ────────────────────────────────────────────────────── */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .stat-cell {
            padding: 30px 20px;
            text-align: center;
            background: rgba(17,24,39,.8);
            border-right: 1px solid var(--border);
        }
        .stat-cell:last-child { border-right: none; }

        .stat-num {
            font-family: 'Space Mono', monospace;
            font-size: clamp(26px, 3.5vw, 42px);
            font-weight: 700;
            color: #fff;
            line-height: 1;
            margin-bottom: 8px;
        }

        .stat-num sup {
            font-size: .48em;
            color: var(--accent);
            vertical-align: super;
        }

        .stat-lbl {
            font-family: 'Space Mono', monospace;
            font-size: 9.5px;
            letter-spacing: .12em;
            color: var(--muted);
            text-transform: uppercase;
        }

        /* ──────────────────────────────────────────────────────
           SECTIONS — shared wrapper
        ────────────────────────────────────────────────────── */
        .section {
            max-width: 1180px;
            margin: 0 auto;
            padding: 88px 28px;
        }

        .section-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Space Mono', monospace;
            font-size: 9.5px;
            letter-spacing: .14em;
            color: var(--accent);
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .section-h {
            font-family: 'Space Mono', monospace;
            font-size: clamp(24px, 3.8vw, 40px);
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
            margin-bottom: 14px;
        }

        .section-p {
            font-size: 15px;
            color: var(--muted);
            max-width: 540px;
            line-height: 1.75;
        }

        /* ──────────────────────────────────────────────────────
           FEATURES GRID
        ────────────────────────────────────────────────────── */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-top: 52px;
            align-items: start;     /* ← prevents height stretching causing visual bleed */
        }

        .feat-card {
            position: relative;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 28px;
            overflow: hidden;
            transition: border-color .2s, transform .2s, box-shadow .2s;
        }

        .feat-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--accent), var(--accent2));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform .25s ease;
        }

        .feat-card:hover {
            border-color: rgba(0,229,255,.2);
            transform: translateY(-4px);
            box-shadow: 0 18px 48px rgba(0,0,0,.28), 0 0 24px rgba(0,229,255,.05);
        }

        .feat-card:hover::after { transform: scaleX(1); }

        .feat-icon {
            width: 46px; height: 46px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 18px;
            flex-shrink: 0;
        }

        .feat-icon svg { width: 21px; height: 21px; }

        .fi-cyan   { background: rgba(0,229,255,.08);  border: 1px solid rgba(0,229,255,.18);  }
        .fi-cyan   svg { color: var(--accent); stroke: var(--accent); }

        .fi-purple { background: rgba(124,58,237,.08); border: 1px solid rgba(124,58,237,.18); }
        .fi-purple svg { color: var(--accent2); stroke: var(--accent2); }

        .fi-green  { background: rgba(16,185,129,.08); border: 1px solid rgba(16,185,129,.18); }
        .fi-green  svg { color: #10b981; stroke: #10b981; }

        .feat-title {
            font-family: 'Space Mono', monospace;
            font-size: 13.5px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .feat-desc {
            font-size: 13.5px;
            color: var(--muted);
            line-height: 1.75;
        }

        /* ──────────────────────────────────────────────────────
           HOW IT WORKS
        ────────────────────────────────────────────────────── */
        .steps-wrap {
            background: rgba(17,24,39,.55);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            max-width: 1180px;
            margin: 0 auto;
        }

        .step {
            padding: 52px 40px;
            text-align: center;
            border-right: 1px solid var(--border);
        }
        .step:last-child { border-right: none; }

        .step-num {
            font-family: 'Space Mono', monospace;
            font-size: 44px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 18px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .step-title {
            font-family: 'Space Mono', monospace;
            font-size: 13.5px;
            font-weight: 700;
            color: #fff;
            margin-bottom: 10px;
        }

        .step-desc {
            font-size: 13.5px;
            color: var(--muted);
            line-height: 1.75;
        }

        /* ──────────────────────────────────────────────────────
           DEVICE CHIPS
        ────────────────────────────────────────────────────── */
        .devices-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
            margin-top: 48px;
        }

        .dev-chip {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            padding: 22px 12px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            text-decoration: none;
            transition: border-color .2s, background .2s, transform .2s;
        }

        .dev-chip:hover {
            border-color: rgba(0,229,255,.25);
            background: rgba(0,229,255,.03);
            transform: translateY(-3px);
        }

        .dev-chip-icon { font-size: 26px; line-height: 1; }

        .dev-chip-name {
            font-family: 'Space Mono', monospace;
            font-size: 9px;
            letter-spacing: .1em;
            color: var(--muted);
            text-transform: uppercase;
            text-align: center;
        }

        .badge-live {
            font-family: 'Space Mono', monospace;
            font-size: 9px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(16,185,129,.12);
            color: #10b981;
            letter-spacing: .05em;
        }

        .badge-soon {
            font-family: 'Space Mono', monospace;
            font-size: 9px;
            padding: 2px 8px;
            border-radius: 999px;
            background: rgba(100,116,139,.12);
            color: var(--muted);
            letter-spacing: .05em;
        }

        /* ──────────────────────────────────────────────────────
           CTA CARD
        ────────────────────────────────────────────────────── */
        .cta-section {
            padding: 88px 28px;
            position: relative;
        }

        .cta-glow {
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(ellipse 70% 60% at 50% 50%, rgba(0,229,255,.07), transparent 70%);
        }

        .cta-card {
            position: relative;
            max-width: 680px;
            margin: 0 auto;
            padding: 60px 48px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 24px;
            text-align: center;
            overflow: hidden;
        }

        /* gradient top border */
        .cta-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent 5%, var(--accent) 35%, var(--accent2) 65%, transparent 95%);
        }

        .cta-title {
            font-family: 'Space Mono', monospace;
            font-size: clamp(22px, 3.5vw, 34px);
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
            margin-bottom: 14px;
        }

        .cta-sub {
            font-size: 14.5px;
            color: var(--muted);
            line-height: 1.75;
            margin-bottom: 32px;
        }

        .cta-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }

        /* ──────────────────────────────────────────────────────
           FOOTER
        ────────────────────────────────────────────────────── */
        .site-footer {
            border-top: 1px solid var(--border);
            padding: 28px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .footer-links {
            display: flex;
            gap: 18px;
        }

        .footer-link {
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
            transition: color .15s;
        }
        .footer-link:hover { color: #e2e8f0; }

        .footer-copy {
            font-family: 'Space Mono', monospace;
            font-size: 9.5px;
            letter-spacing: .08em;
            color: #334155;
        }

        /* ──────────────────────────────────────────────────────
           RESPONSIVE
        ────────────────────────────────────────────────────── */
        @media (max-width: 1024px) {
            .features-grid  { grid-template-columns: repeat(2, 1fr); }
            .devices-grid   { grid-template-columns: repeat(3, 1fr); }
        }

        @media (max-width: 768px) {
            .navbar         { padding: 0 16px; }
            .brand-name     { font-size: 11px; }
            .stats-bar      { grid-template-columns: repeat(2, 1fr); }
            .stat-cell:nth-child(2) { border-right: none; }
            .stat-cell:nth-child(3) { border-top: 1px solid var(--border); }
            .stat-cell:nth-child(4) { border-top: 1px solid var(--border); border-right: none; }
            .steps-grid     { grid-template-columns: 1fr; }
            .step           { border-right: none; border-bottom: 1px solid var(--border); padding: 36px 24px; }
            .step:last-child { border-bottom: none; }
            .orbit-wrap     { width: 220px; height: 220px; }
            .orbit-chip     { width: 36px; height: 36px; font-size: 14px; }
            .orbit-core-icon { font-size: 22px; }
            .section        { padding: 64px 20px; }
            .cta-card       { padding: 44px 24px; }
        }

        @media (max-width: 600px) {
            .nav-ghost          { display: none; }
            .orbit-wrap         { display: none; }
            .hero               { padding: 48px 20px 44px; }
            .hero-cta           { gap: 10px; }
            .btn-xl             { padding: 12px 22px; font-size: 14px; }
            .features-grid      { grid-template-columns: 1fr; }
            .devices-grid       { grid-template-columns: repeat(2, 1fr); }
            .site-footer        { flex-direction: column; text-align: center; padding: 24px 20px; }
            .footer-links       { justify-content: center; }
        }
    </style>
</head>
<body>

<!-- ── Fixed background layers (below everything) ── -->
<div class="bg-layer" aria-hidden="true"></div>
<div class="bg-glow"  aria-hidden="true"></div>

<!-- ══════════════════════════════════════════════════════ NAVBAR ══ -->
<nav class="navbar" role="navigation">
    <a href="/" class="brand">
        <span class="pulse-dot"></span>
        <span class="brand-name">SmartHome</span>
    </a>

    <div class="nav-links">
        @auth
            <a href="{{ route('devices.index') }}" class="nav-btn nav-ghost">Devices</a>
            <a href="{{ route('dashboard') }}" class="nav-btn nav-primary">
                <svg width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Dashboard
            </a>
        @else
            <a href="{{ route('login') }}"    class="nav-btn nav-ghost">Sign In</a>
            <a href="{{ route('register') }}" class="nav-btn nav-primary">Get Started →</a>
        @endauth
    </div>
</nav>

<!-- ══════════════════════════════════════════════════════ MAIN ══ -->
<main>

    <!-- ── LIVE TICKER ── -->
    <div class="ticker-wrap" aria-label="Live sensor data feed">

        {{-- Fixed "LIVE FEED" badge — sits at the left edge, never scrolls --}}
        <div class="ticker-label">
            <span class="pulse-dot"></span>
            Live Feed
        </div>

        {{-- Scrollable viewport — clips the track so it never reaches the badge --}}
        <div class="ticker-scroll">
            <div class="ticker-track">
                <span class="ticker-item">⚡ Voltage <span class="tv">228.4 V</span></span>
                <span class="ticker-item">⚡ Current <span class="tv">1.24 A</span></span>
                <span class="ticker-item">⚡ Power <span class="tv">84.3 W</span></span>
                <span class="ticker-item">🌡 Temperature <span class="tv">23.1 °C</span></span>
                <span class="ticker-item">📡 Frequency <span class="tv">50.0 Hz</span></span>
                <span class="ticker-item">🔒 Front Door <span class="tv">Locked</span></span>
                <span class="ticker-item">💧 Humidity <span class="tv">61 %</span></span>
                <span class="ticker-item">📷 Camera Hall <span class="tv">Online</span></span>
                <span class="ticker-item">⚡ Power Factor <span class="tv">0.94</span></span>
                <span class="ticker-item">🔌 Smart Plug <span class="tv">Active</span></span>
                {{-- duplicate set — translateX(-50%) loops back to start --}}
                <span class="ticker-item">⚡ Voltage <span class="tv">228.4 V</span></span>
                <span class="ticker-item">⚡ Current <span class="tv">1.24 A</span></span>
                <span class="ticker-item">⚡ Power <span class="tv">84.3 W</span></span>
                <span class="ticker-item">🌡 Temperature <span class="tv">23.1 °C</span></span>
                <span class="ticker-item">📡 Frequency <span class="tv">50.0 Hz</span></span>
                <span class="ticker-item">🔒 Front Door <span class="tv">Locked</span></span>
                <span class="ticker-item">💧 Humidity <span class="tv">61 %</span></span>
                <span class="ticker-item">📷 Camera Hall <span class="tv">Online</span></span>
                <span class="ticker-item">⚡ Power Factor <span class="tv">0.94</span></span>
                <span class="ticker-item">🔌 Smart Plug <span class="tv">Active</span></span>
            </div>
        </div>

    </div>

    <!-- ══════════════════════════════════════════════════ HERO ══ -->
    <section class="hero">

        <div class="hero-pill">
            <span class="pulse-dot" style="width:5px;height:5px;"></span>
            IoT Smart Home Platform — Now Live
        </div>

        <h1 class="hero-title">
            Your Entire Home,<br>
            <span class="c1">Intelligently</span>
            <span class="c2"> Connected</span>
        </h1>

        <p class="hero-sub">
            Monitor every device, track every reading, and automate your smart home
            from one unified platform. Real-time MQTT data, role-based access,
            and enterprise-grade reliability.
        </p>

        <div class="hero-cta">
            @auth
                <a href="{{ route('dashboard') }}" class="btn-xl btn-filled">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                    </svg>
                    Go to Dashboard
                </a>
                <a href="{{ route('devices.index') }}" class="btn-xl btn-ghost-xl">View My Devices →</a>
            @else
                <a href="{{ route('register') }}" class="btn-xl btn-filled">
                    Start for Free
                    <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
                <a href="{{ route('login') }}" class="btn-xl btn-ghost-xl">Sign In</a>
            @endauth
        </div>

        <!-- orbit device visual — hidden on small screens via CSS -->
        <div class="orbit-wrap" aria-hidden="true">
            <div class="ring ring-outer"></div>
            <div class="ring ring-inner"></div>
            <div class="orbit-core">
                <div class="orbit-core-icon">⚡</div>
                <div class="orbit-core-lbl">Live</div>
            </div>
            <div class="orbit-chip oc-top">📡</div>
            <div class="orbit-chip oc-right">📷</div>
            <div class="orbit-chip oc-bottom">🔒</div>
            <div class="orbit-chip oc-left">🌡️</div>
        </div>

    </section>

    <!-- ════════════════════════════════════════════════ STATS BAR ══ -->
    <div class="stats-bar">
        <div class="stat-cell">
            <div class="stat-num">10<sup>K+</sup></div>
            <div class="stat-lbl">Devices Supported</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">30<sup>s</sup></div>
            <div class="stat-lbl">Refresh Interval</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">99<sup>%</sup></div>
            <div class="stat-lbl">Uptime SLA</div>
        </div>
        <div class="stat-cell">
            <div class="stat-num">3</div>
            <div class="stat-lbl">Access Roles</div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════ FEATURES ══ -->
    <div class="section">
        <div class="section-tag">
            <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            Platform Capabilities
        </div>
        <h2 class="section-h">Everything you need<br>to monitor your smart home</h2>
        <p class="section-p">
            Built on battle-tested technology — MQTT, Laravel, and real-time WebSockets —
            so you focus on your devices, not infrastructure.
        </p>

        <div class="features-grid">

            <div class="feat-card">
                <div class="feat-icon fi-cyan">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div class="feat-title">Real-Time Monitoring</div>
                <div class="feat-desc">Live MQTT telemetry with 30-second auto-refresh, WebSocket push events, and 5-chart dashboards. Voltage, current, power, energy, and frequency — all at once.</div>
            </div>

            <div class="feat-card">
                <div class="feat-icon fi-purple">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <div class="feat-title">Role-Based Access</div>
                <div class="feat-desc">Three-tier access control: Super Admin owns everything, Admin manages all users and devices, and Users see only their own — enforced at the policy layer.</div>
            </div>

            <div class="feat-card">
                <div class="feat-icon fi-green">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                    </svg>
                </div>
                <div class="feat-title">Multi-Device Support</div>
                <div class="feat-desc">Meters are live today. Sensors, cameras, thermostats, smart plugs, and locks are architected in — each device type unlocks its own dashboard progressively.</div>
            </div>

            <div class="feat-card">
                <div class="feat-icon fi-cyan">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                    </svg>
                </div>
                <div class="feat-title">Secure MQTT Pipeline</div>
                <div class="feat-desc">Hardened MQTT consumer with payload validation, error recovery, and availability heartbeat detection. Every ingestion event is audit-logged end-to-end.</div>
            </div>

            <div class="feat-card">
                <div class="feat-icon fi-purple">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                </div>
                <div class="feat-title">Smart Alerts</div>
                <div class="feat-desc">Alert lifecycle tracking — raised, acknowledged, and resolved. Know exactly when something went wrong and when it recovered, with full event history.</div>
            </div>

            <div class="feat-card">
                <div class="feat-icon fi-green">
                    <svg fill="none" viewBox="0 0 24 24" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                </div>
                <div class="feat-title">Historical Analytics</div>
                <div class="feat-desc">Time-range filtering — 1H, 6H, 24H, Today, 7 Days. Incremental cursor API ensures zero duplicate data and instant chart updates on range switch.</div>
            </div>

        </div>
    </div>

    <!-- ════════════════════════════════════════════ HOW IT WORKS ══ -->
    <div class="steps-wrap">
        <div class="section" style="padding-bottom: 0; padding-top: 72px; text-align: center;">
            <div class="section-tag" style="justify-content:center;">
                <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                How It Works
            </div>
            <h2 class="section-h" style="margin:12px auto 64px; max-width:500px;">Up and running in three steps</h2>
        </div>
        <div class="steps-grid">
            <div class="step">
                <div class="step-num">01</div>
                <div class="step-title">Create Your Account</div>
                <div class="step-desc">Sign up with your details. Admins can onboard users directly from the platform — no email setup required during early access.</div>
            </div>
            <div class="step">
                <div class="step-num">02</div>
                <div class="step-title">Register Your Devices</div>
                <div class="step-desc">Add a device with its MQTT topic and type. The consumer subscribes immediately — readings start flowing within seconds of your first publish.</div>
            </div>
            <div class="step">
                <div class="step-num">03</div>
                <div class="step-title">Monitor in Real-Time</div>
                <div class="step-desc">Open the live dashboard and watch voltage, current, power, and energy update every 30 seconds — with 5 charts, KPI cards, and a scrollable readings table.</div>
            </div>
        </div>
        <!-- bottom padding for the steps-wrap section -->
        <div style="height: 64px;"></div>
    </div>

    <!-- ════════════════════════════════════════ DEVICE TYPES ══ -->
    <div class="section">
        <div class="section-tag">
            <svg width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            Supported Devices
        </div>
        <h2 class="section-h">One platform, every device</h2>
        <p class="section-p">Meters are live and fully operational. Every other type is wired into the architecture — dashboards unlock as we build them.</p>

        <div class="devices-grid">
            <div class="dev-chip">
                <div class="dev-chip-icon">⚡</div>
                <div class="dev-chip-name">Energy Meter</div>
                <span class="badge-live">Live</span>
            </div>
            <div class="dev-chip">
                <div class="dev-chip-icon">📡</div>
                <div class="dev-chip-name">Sensor</div>
                <span class="badge-soon">Soon</span>
            </div>
            <div class="dev-chip">
                <div class="dev-chip-icon">📷</div>
                <div class="dev-chip-name">Camera</div>
                <span class="badge-soon">Soon</span>
            </div>
            <div class="dev-chip">
                <div class="dev-chip-icon">🌡️</div>
                <div class="dev-chip-name">Thermostat</div>
                <span class="badge-soon">Soon</span>
            </div>
            <div class="dev-chip">
                <div class="dev-chip-icon">🔌</div>
                <div class="dev-chip-name">Smart Plug</div>
                <span class="badge-soon">Soon</span>
            </div>
            <div class="dev-chip">
                <div class="dev-chip-icon">🔒</div>
                <div class="dev-chip-name">Smart Lock</div>
                <span class="badge-soon">Soon</span>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════ CTA CARD ══ -->
    <section class="cta-section">
        <div class="cta-glow" aria-hidden="true"></div>
        <div class="cta-card">
            <div class="hero-pill" style="margin-bottom:20px;">
                <span class="pulse-dot" style="width:5px;height:5px;"></span>
                @auth Ready to explore? @else Start monitoring today @endauth
            </div>

            @auth
                <div class="cta-title">Welcome back,<br>{{ Auth::user()->name }}</div>
                <p class="cta-sub">Your smart home dashboard is ready. Check the latest readings and device status.</p>
                <div class="cta-btns">
                    <a href="{{ route('dashboard') }}"   class="btn-xl btn-filled">Go to Dashboard →</a>
                    <a href="{{ route('devices.index') }}" class="btn-xl btn-ghost-xl">View Devices</a>
                </div>
            @else
                <div class="cta-title">Ready to connect<br>your smart home?</div>
                <p class="cta-sub">Set up your first device in under 5 minutes. No credit card required.</p>
                <div class="cta-btns">
                    <a href="{{ route('register') }}" class="btn-xl btn-filled">Create Free Account →</a>
                    <a href="{{ route('login') }}"    class="btn-xl btn-ghost-xl">Sign In</a>
                </div>
            @endauth
        </div>
    </section>

</main>

<!-- ══════════════════════════════════════════════════════ FOOTER ══ -->
<footer class="site-footer" role="contentinfo">
    <div class="brand">
        <span class="pulse-dot" style="width:6px;height:6px;"></span>
        <span class="brand-name" style="font-size:11px;color:#334155;">SmartHome IoT Platform</span>
    </div>

    <nav class="footer-links">
        @auth
            <a href="{{ route('dashboard') }}"   class="footer-link">Dashboard</a>
            <a href="{{ route('devices.index') }}" class="footer-link">Devices</a>
            <a href="{{ route('profile.edit') }}"  class="footer-link">Profile</a>
        @else
            <a href="{{ route('login') }}"    class="footer-link">Sign In</a>
            <a href="{{ route('register') }}" class="footer-link">Register</a>
        @endauth
    </nav>

    <div class="footer-copy">© {{ date('Y') }} · MQTT-POWERED · REAL-TIME</div>
</footer>

</body>
</html>
