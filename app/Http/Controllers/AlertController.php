<?php

namespace App\Http\Controllers;

use App\Models\AlertEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    /**
     * The alerts console — "everything I can see" (distinct from the bell, which
     * is "what was delivered to me"). Visibility is scoped by AlertEvent's
     * visibleTo() — a user sees only their own devices' alerts, an admin the
     * whole fleet — reusing the same rule as device visibility.
     */
    public function index(Request $request): View
    {
        $status   = $request->query('status');
        $severity = $request->query('severity');

        $alerts = AlertEvent::query()
            ->visibleTo($request->user())
            ->with('device')
            ->when(in_array($status, ['open', 'resolved'], true), fn ($q) => $q->where('status', $status))
            ->when(in_array($severity, ['warning', 'critical'], true), fn ($q) => $q->where('severity', $severity))
            ->orderByDesc('triggered_at')
            ->paginate(30)
            ->withQueryString();

        return view('alerts.index', [
            'alerts'  => $alerts,
            'filters' => ['status' => $status, 'severity' => $severity],
        ]);
    }
}
