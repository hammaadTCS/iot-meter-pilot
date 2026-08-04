<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A9 — baseline transport/response hardening.
 *
 * Two defects motivated this: SESSION_SECURE_COOKIE was absent from both env
 * files AND had no default in config/session.php, so it resolved to null and
 * production session cookies were never marked Secure; and no security response
 * headers were sent at all.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent_on_web_responses(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->assertStringContainsString(
            'geolocation=()',
            $response->headers->get('Permissions-Policy')
        );
    }

    public function test_security_headers_are_sent_on_api_responses(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_csp_locks_down_framing_objects_and_base_uri(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    /**
     * The dashboards load Chart.js from jsDelivr and Google Fonts from
     * googleapis/gstatic. If the CSP omits these the charts and typography
     * break silently in the browser while every test still passes, so they are
     * pinned here deliberately.
     */
    public function test_csp_allows_the_assets_the_dashboards_actually_load(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://cdn.jsdelivr.net', $csp);      // Chart.js
        $this->assertStringContainsString('https://fonts.googleapis.com', $csp);  // font CSS
        $this->assertStringContainsString('https://fonts.gstatic.com', $csp);     // font files
        $this->assertStringContainsString('ws:', $csp);                           // Reverb socket
    }

    /**
     * Regression: the first cut of this policy omitted 'unsafe-eval' and broke
     * every page. Alpine — bundled inside Livewire — compiles each directive
     * expression (x-data, x-show, @click) with `new AsyncFunction(...)`, which
     * CSP treats as eval. Dropping this again requires migrating to Alpine's
     * CSP build, not just tightening the header.
     */
    public function test_csp_permits_the_eval_alpine_requires(): void
    {
        $csp = $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("'unsafe-eval'", $csp);
    }

    /**
     * Regression: `npm run dev` serves assets from the Vite dev server on a
     * different origin (:5173), which 'self' does not cover — the first cut
     * blocked @vite/client, app.js and app.css outright.
     */
    public function test_csp_allows_the_vite_dev_server_while_it_is_running(): void
    {
        $hot = public_path('hot');
        $existed = file_exists($hot);
        $previous = $existed ? file_get_contents($hot) : null;

        file_put_contents($hot, 'http://127.0.0.1:5173');

        try {
            $csp = $this->get('/')->headers->get('Content-Security-Policy');

            // Needed by @vite/client + app.js, app.css, and the HMR socket.
            $this->assertMatchesRegularExpression('/script-src[^;]*127\.0\.0\.1:5173/', $csp);
            $this->assertMatchesRegularExpression('/style-src[^;]*127\.0\.0\.1:5173/', $csp);
            $this->assertMatchesRegularExpression('/connect-src[^;]*127\.0\.0\.1:5173/', $csp);
        } finally {
            $existed ? file_put_contents($hot, $previous) : @unlink($hot);
        }
    }

    /** The dev-server escape hatch must never widen a production policy. */
    public function test_production_csp_never_includes_the_vite_dev_server(): void
    {
        $hot = public_path('hot');
        $existed = file_exists($hot);
        $previous = $existed ? file_get_contents($hot) : null;

        file_put_contents($hot, 'http://127.0.0.1:5173');
        $this->app['env'] = 'production';

        try {
            $csp = $this->get('/')->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('127.0.0.1:5173', $csp);
        } finally {
            $existed ? file_put_contents($hot, $previous) : @unlink($hot);
        }
    }

    /**
     * The regression that mattered: a missing env key must not leave this null.
     * config/session.php now defaults it from APP_ENV, so outside production it
     * is an explicit false rather than an implicit "unset".
     */
    public function test_secure_cookie_setting_resolves_to_a_boolean_not_null(): void
    {
        $this->assertNotNull(
            config('session.secure'),
            'session.secure resolved to null — a missing SESSION_SECURE_COOKIE would ship insecure cookies.'
        );
        $this->assertIsBool(config('session.secure'));
    }

    public function test_headers_are_present_on_authenticated_pages_too(): void
    {
        $user = User::factory()->consumer()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
