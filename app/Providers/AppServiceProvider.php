<?php

namespace App\Providers;

use App\Models\Device;
use App\Notifications\Channels\ResilientBroadcastChannel;
use App\Policies\DevicePolicy;
use Illuminate\Notifications\Channels\BroadcastChannel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
