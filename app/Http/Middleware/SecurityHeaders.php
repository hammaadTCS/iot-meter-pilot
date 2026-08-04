<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security response headers.
 *
 * READ THIS BEFORE TIGHTENING script-src.
 *
 * The policy is deliberately permissive about scripts, for two independent
 * reasons that both have to be solved before it can be tightened:
 *
 *  1. 'unsafe-inline' — the meter dashboards are ~2900-line Blade files with
 *     their JavaScript inline. Blocked on extracting that JS (frontend plan F1).
 *  2. 'unsafe-eval'  — Alpine (bundled inside Livewire) compiles every
 *     directive expression, e.g. x-data="{ open: false }", with
 *     `new AsyncFunction(...)`. CSP classes that as eval. Removing it requires
 *     migrating to Alpine's CSP-friendly build, which forbids expressions in
 *     markup entirely and needs every x-data/x-show/@click rewritten as a
 *     registered component object. That is a much larger job than F1 alone.
 *
 * So script-src is NOT currently providing XSS protection — be honest about
 * that. What this header does buy today is frame-ancestors (clickjacking),
 * object-src (plugin injection), base-uri (base-tag hijacking) and form-action
 * (form exfiltration), none of which depend on the two escapes above.
 *
 * connect-src includes ws:/wss: for the Reverb websocket.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Downloads (CSV/NDJSON exports) stream their body; adding headers is
        // still fine, but skip anything that would alter caching semantics.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Deny by default; the app needs none of these.
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=()'
        );

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->policy());
        }

        return $response;
    }

    private function policy(): string
    {
        // Google Fonts serves its CSS from fonts.googleapis.com and the font
        // files from fonts.gstatic.com (layouts/app, layouts/guest, welcome);
        // Chart.js comes from jsDelivr in both meter dashboards.
        $script = ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://cdn.jsdelivr.net'];
        $style = ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'];
        $connect = ["'self'", 'ws:', 'wss:'];

        // `npm run dev` serves assets from the Vite dev server on a different
        // origin (port 5173), which 'self' does not cover. Only while the hot
        // file exists — production builds are served from 'self' and never
        // widen the policy.
        if ($devServer = $this->viteDevServer()) {
            $script[] = $devServer;
            $style[] = $devServer;
            $connect[] = $devServer;
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "img-src 'self' data:",
            "font-src 'self' data: https://fonts.gstatic.com",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'connect-src '.implode(' ', $connect),
        ]);
    }

    /** The Vite dev server origin while `npm run dev` is running, else null. */
    private function viteDevServer(): ?string
    {
        if (app()->environment('production') || ! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents(public_path('hot')));

        return $url !== '' ? rtrim($url, '/') : null;
    }
}
