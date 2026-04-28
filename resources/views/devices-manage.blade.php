<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Meters</title>
    @vite(['resources/js/app.js'])
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #0b0f1a;
            --surface: #111827;
            --surface2: #1a2235;
            --border: #1f2d45;
            --accent: #00e5ff;
            --accent2: #7c3aed;
            --green: #10b981;
            --red: #ef4444;
            --text: #e2e8f0;
            --muted: #64748b;
            --font-body: 'DM Sans', sans-serif;
            --font-mono: 'Space Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: radial-gradient(circle at top left, rgba(124, 58, 237, .12), transparent 30%), var(--bg);
            color: var(--text);
            font-family: var(--font-body);
            padding: 32px 24px 80px;
        }

        .shell {
            max-width: 1180px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .eyebrow {
            margin-bottom: 8px;
            color: var(--accent);
            font: 700 12px/1 var(--font-mono);
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        h1 {
            margin-bottom: 8px;
            font-size: clamp(28px, 4vw, 40px);
        }

        .subcopy {
            color: var(--muted);
            max-width: 760px;
            line-height: 1.6;
        }

        .nav-link {
            display: inline-flex;
            align-items: center;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            color: var(--text);
            text-decoration: none;
            transition: border-color .2s, transform .2s;
        }

        .nav-link:hover {
            border-color: var(--accent);
            transform: translateY(-1px);
        }

        .grid {
            display: grid;
            grid-template-columns: minmax(320px, 400px) minmax(0, 1fr);
            gap: 24px;
        }

        @media (max-width: 920px) {
            .grid { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .2);
        }

        .card-title {
            margin-bottom: 18px;
            color: var(--muted);
            font: 700 12px/1 var(--font-mono);
            letter-spacing: .14em;
            text-transform: uppercase;
        }

        .stack {
            display: grid;
            gap: 14px;
        }

        label {
            display: grid;
            gap: 8px;
            color: var(--muted);
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #0f172a;
            color: var(--text);
            font: 400 14px/1.4 var(--font-body);
        }

        input[type="text"]:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0, 229, 255, .12);
        }

        .checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
        }

        .checkbox input {
            width: 18px;
            height: 18px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            padding: 0 16px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: transparent;
            color: var(--text);
            cursor: pointer;
            transition: border-color .2s, transform .2s, background .2s;
            text-decoration: none;
            font: 600 14px/1 var(--font-body);
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn--primary {
            background: rgba(0, 229, 255, .12);
            border-color: rgba(0, 229, 255, .35);
            color: var(--accent);
        }

        .btn--primary:hover {
            border-color: var(--accent);
        }

        .btn--danger {
            color: #fecaca;
            border-color: rgba(239, 68, 68, .35);
            background: rgba(239, 68, 68, .08);
        }

        .btn--danger:hover {
            border-color: var(--red);
        }

        .note {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(124, 58, 237, .25);
            background: rgba(124, 58, 237, .08);
            color: var(--muted);
            line-height: 1.6;
        }

        .table-wrap {
            overflow: auto;
            border-radius: 16px;
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1080px;
        }

        th,
        td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--surface2);
            color: var(--muted);
            font: 700 11px/1 var(--font-mono);
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        td {
            vertical-align: top;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .cell-code,
        .cell-topic {
            font-family: var(--font-mono);
            font-size: 13px;
        }

        .cell-topic-sub {
            display: block;
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            max-width: 280px;
            word-break: break-word;
        }

        .cell-last-seen {
            font-family: var(--font-mono);
            font-size: 13px;
            color: var(--muted);
            white-space: nowrap;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font: 700 11px/1 var(--font-mono);
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .status--enabled,
        .status--online {
            color: var(--green);
            background: rgba(16, 185, 129, .12);
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .status--stale {
            color: #fcd34d;
            background: rgba(245, 158, 11, .12);
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .status--silent {
            color: #fde68a;
            background: rgba(245, 158, 11, .12);
            border: 1px solid rgba(245, 158, 11, .25);
        }

        .status--error {
            color: #fecaca;
            background: rgba(239, 68, 68, .12);
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .status--ok,
        .status--recovered {
            color: var(--green);
            background: rgba(16, 185, 129, .12);
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .status--down {
            color: #fca5a5;
            background: rgba(239, 68, 68, .12);
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .status--offline {
            color: #fecaca;
            background: rgba(239, 68, 68, .12);
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .status--never_seen,
        .status--unknown,
        .status--disabled {
            color: #cbd5f5;
            background: rgba(148, 163, 184, .12);
            border: 1px solid rgba(148, 163, 184, .25);
        }

        .message {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
            display: none;
        }

        .message.is-visible { display: block; }
        .message--success {
            color: #d1fae5;
            background: rgba(16, 185, 129, .12);
            border: 1px solid rgba(16, 185, 129, .25);
        }

        .message--error {
            color: #fecaca;
            background: rgba(239, 68, 68, .12);
            border: 1px solid rgba(239, 68, 68, .25);
        }

        .issue-meta {
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--muted);
            max-width: 250px;
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="header">
            <div>
                <div class="eyebrow">Meter Pilot</div>
                <h1>Manage Meters</h1>
                <p class="subcopy">
                    Add and remove meter records here. Enabled meters are subscribed by the MQTT consumer,
                    while Availability reflects MQTT presence, Health reflects telemetry freshness, and Issue
                    shows the latest matched-topic payload problem separately from both.
                </p>
            </div>

            <a class="nav-link" href="/">Back to Dashboard</a>
        </header>

        <div class="grid">
            <section class="card">
                <div class="card-title">Add Meter</div>

                <div id="pageMessage" class="message" role="status" aria-live="polite"></div>

                <form id="deviceForm" class="stack">
                    <label for="code">
                        Device Code
                        <input id="code" name="code" type="text" placeholder="e.g. meter-shop-201" required>
                    </label>

                    <label for="name">
                        Display Name
                        <input id="name" name="name" type="text" placeholder="e.g. Shop 201 Meter" required>
                    </label>

                    <label for="mqtt_topic">
                        MQTT Topic
                        <input id="mqtt_topic" name="mqtt_topic" type="text" placeholder="e.g. meters/shop-201" required>
                    </label>

                    <label for="availability_topic">
                        Availability Topic <span style="color: var(--muted);">(optional)</span>
                        <input id="availability_topic" name="availability_topic" type="text" placeholder="e.g. meters/shop-201/status">
                    </label>

                    <label class="checkbox" for="is_active">
                        <input id="is_active" name="is_active" type="checkbox" checked>
                        <span>Subscribe to this meter immediately after the consumer is restarted.</span>
                    </label>

                    <div class="actions">
                        <button class="btn btn--primary" type="submit">Add Meter</button>
                    </div>
                </form>

                <div class="note">
                    After adding or deleting a meter, restart <code>php artisan mqtt:consume-meter</code>
                    so the long-running consumer refreshes both data-topic and availability-topic subscriptions.
                </div>
            </section>

            <section class="card">
                <div class="card-title">Configured Meters</div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Code</th>
                                <th>Topics</th>
                                <th>Enabled</th>
                                <th>Availability</th>
                                <th>Health</th>
                                <th>Issue</th>
                                <th>Last Seen</th>
                                <th>Dashboard</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($devices as $device)
                                @php($availability = $device->availabilitySnapshot())
                                @php($health = $device->healthSnapshot())
                                @php($issue = $device->issueSnapshot())
                                <tr>
                                    <td>{{ $device->name }}</td>
                                    <td class="cell-code">{{ $device->code }}</td>
                                    <td class="cell-topic">
                                        {{ $device->mqtt_topic }}
                                        <span class="cell-topic-sub">
                                            Status: {{ $device->resolvedAvailabilityTopic() ?? '—' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status {{ $device->is_active ? 'status--enabled' : 'status--disabled' }}">
                                            {{ $device->is_active ? 'Enabled' : 'Disabled' }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status status--{{ $availability['status'] }}">
                                            {{ $availability['label'] }}
                                        </span>

                                        <div class="issue-meta">
                                            {{ $availability['message'] }}
                                            @if ($availability['last_availability_at'])
                                                <br>
                                                Last availability: {{ optional($device->last_availability_at)->format('d M Y H:i:s') }}
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status status--{{ $health['status'] }}">
                                            {{ $health['label'] }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status status--{{ $issue['status'] }}">
                                            {{ $issue['label'] }}
                                        </span>

                                        @if ($issue['has_issue'])
                                            <div class="issue-meta">
                                                {{ $issue['error_message'] }}
                                                @if ($issue['last_error_at'])
                                                    <br>
                                                    Last invalid message: {{ optional($device->last_error_at)->format('d M Y H:i:s') }}
                                                @endif
                                            </div>
                                        @elseif ($issue['status'] === 'recovered')
                                            <div class="issue-meta">
                                                Valid telemetry resumed
                                                @if ($issue['last_recovered_at'])
                                                    {{ optional($device->last_recovered_at)->format('d M Y H:i:s') }}
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="cell-last-seen">{{ optional($device->last_seen_at)->format('d M Y H:i:s') ?? '—' }}</td>
                                    <td>
                                        <a class="btn" href="/?device={{ $device->id }}">Open</a>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn--danger"
                                            data-delete-device="{{ $device->id }}"
                                            data-device-name="{{ $device->name }}"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10">No meters found yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <script>
    "use strict";

    const messageEl = document.getElementById('pageMessage');

    function showMessage(type, text) {
        messageEl.className = `message message--${type} is-visible`;
        messageEl.textContent = text;
    }

    async function readJson(response) {
        try {
            return await response.json();
        } catch (error) {
            return null;
        }
    }

    // Create a new meter from the management form.
    document.getElementById('deviceForm').addEventListener('submit', async (event) => {
        event.preventDefault();

        const payload = {
            code: document.getElementById('code').value.trim(),
            name: document.getElementById('name').value.trim(),
            type: 'meter',
            mqtt_topic: document.getElementById('mqtt_topic').value.trim(),
            availability_topic: document.getElementById('availability_topic').value.trim(),
            is_active: document.getElementById('is_active').checked,
        };

        const response = await fetch('/api/devices', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            const error = await readJson(response);
            console.error(error);
            showMessage('error', 'Failed to create meter. Check the code, data topic, and availability topic for duplicates.');
            return;
        }

        showMessage('success', 'Meter created successfully. Restart mqtt:consume-meter to subscribe to the new data and availability topics.');
        window.location.reload();
    });

    // Deleting a device removes its latest state and historical readings through
    // database-level cascade deletes.
    document.querySelectorAll('[data-delete-device]').forEach((button) => {
        button.addEventListener('click', async () => {
            const id = button.dataset.deleteDevice;
            const name = button.dataset.deviceName;

            if (!confirm(`Delete ${name}? This also removes all related readings and latest state.`)) {
                return;
            }

            const response = await fetch(`/api/devices/${id}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                },
            });

            if (!response.ok) {
                const error = await readJson(response);
                console.error(error);
                showMessage('error', 'Failed to delete meter.');
                return;
            }

            showMessage('success', 'Meter deleted successfully. Restart mqtt:consume-meter to refresh subscriptions.');
            window.location.reload();
        });
    });
    </script>
</body>
</html>
