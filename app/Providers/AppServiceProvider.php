<?php

namespace App\Providers;

use App\Models\Device;
use App\Notifications\Channels\ResilientBroadcastChannel;
use App\Policies\DevicePolicy;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Device::class => DevicePolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Make the notification broadcast channel non-fatal.
         *
         * ChannelManager::createBroadcastDriver() resolves BroadcastChannel out
         * of the container, so binding our subclass here swaps the behaviour
         * everywhere without any notification having to opt in — `via()` keeps
         * returning the plain 'broadcast' string.
         *
         * See ResilientBroadcastChannel for the incident this prevents.
         */
        $this->app->bind(BroadcastChannel::class, ResilientBroadcastChannel::class);
    }

    /**
     * Infrastructure settings that must be present for the app to be trustworthy.
     *
     * Keyed by config path => the .env key a human needs to go and fix.
     *
     * @var array<string, string>
     */
    protected const REQUIRED_CONFIG = [
        'mqtt-client.connections.default.host' => 'MQTT_HOST',
        'mqtt-client.connections.default.client_id' => 'MQTT_CLIENT_ID',
        'database.connections.mysql.database' => 'DB_DATABASE',
        'app.key' => 'APP_KEY',
    ];

    /**
     * Refuse to boot when infrastructure configuration is missing.
     *
     * WHAT THIS CATCHES: a key that is absent or blank. Combined with removing the
     * localhost fallback in config/mqtt-client.php, a deleted MQTT_HOST line now
     * stops the app with a message naming the key, instead of silently connecting
     * to this machine and reporting itself healthy while ingesting nothing.
     *
     * WHAT THIS DOES NOT CATCH: a key that is present but wrong. In the 2026-08-28
     * incident `.env` was regenerated from `.env.example` and contained
     * MQTT_HOST=127.0.0.1 — present, and wrong. This guard would have passed it.
     * That case is caught by meters:scan-health (which did fire correctly, at 05:38)
     * and depends on alert delivery working, not on configuration validation.
     *
     * Skipped under tests: the suite runs on in-memory SQLite with no broker and
     * must not require real infrastructure to be present.
     */
    protected function assertRequiredConfiguration(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $missing = [];

        foreach (self::REQUIRED_CONFIG as $configKey => $envKey) {
            $value = config($configKey);

            if ($value === null || $value === '') {
                $missing[] = $envKey;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'Refusing to start: required configuration is missing [%s]. '
                .'Set these in .env. They have no safe default — a fallback here '
                .'would let the application run against the wrong infrastructure '
                .'while reporting itself healthy.',
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // First, before anything can depend on a setting that is not there.
        $this->assertRequiredConfiguration();

        $this->registerPolicies();

        // Generate every URL as https:// in production. Without this, a
        // password-reset or email-verification signed URL built behind a
        // TLS-terminating proxy can come out as http:// and either break the
        // signature or send a session cookie (now Secure) over plaintext.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Hybrid access control (docs/FGAC_IMPLEMENTATION_PLAN.md §3.3):
        // super_admin bypasses every gate, policy and permission check.
        // Returning null (not false) lets all other users fall through to
        // the normal permission evaluation. This must stay the ONLY
        // hasRole() call in application code — everything else checks
        // permissions via can().
        Gate::before(
            fn ($user, $ability) => $user->hasRole('super_admin') ? true : null
        );

        // NOTE: EnqueueAlertForDelivery is auto-discovered for AlertOpened /
        // AlertResolved from its handle() type-hint — no manual registration
        // (adding it here would double-fire the listener).
    }

    /**
     * Register the application's policies.
     */
    protected function registerPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
