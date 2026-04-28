<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>No Meters Configured</title>
    @vite(['resources/js/app.js'])

    <style>
        :root {
            --bg: #0b0f1a;
            --surface: #111827;
            --border: #1f2d45;
            --accent: #00e5ff;
            --text: #e2e8f0;
            --muted: #64748b;
            --font-body: 'DM Sans', sans-serif;
            --font-mono: 'Space Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            background: radial-gradient(circle at top, rgba(0, 229, 255, .08), transparent 35%), var(--bg);
            color: var(--text);
            font-family: var(--font-body);
        }

        .card {
            width: min(560px, 100%);
            padding: 32px;
            border: 1px solid var(--border);
            border-radius: 20px;
            background: var(--surface);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        }

        .eyebrow {
            margin-bottom: 12px;
            color: var(--accent);
            font: 700 12px/1 var(--font-mono);
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        h1 {
            margin-bottom: 12px;
            font-size: clamp(28px, 4vw, 40px);
        }

        p {
            margin-bottom: 18px;
            color: var(--muted);
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 160px;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            transition: border-color .2s, transform .2s;
        }

        .btn:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }

        .btn--primary {
            background: rgba(0, 229, 255, .12);
            border-color: rgba(0, 229, 255, .35);
            color: var(--accent);
        }
    </style>
</head>
<body>
    <main class="card">
        <div class="eyebrow">Meter Pilot</div>
        <h1>No meters configured yet</h1>
        <p>
            Add a meter from the management page to start storing telemetry and viewing
            per-device dashboards.
        </p>

        <div class="actions">
            <a class="btn btn--primary" href="/devices/manage">Manage Meters</a>
        </div>
    </main>
</body>
</html>
