<?php

namespace App\Providers;

use App\Models\Device;
use App\Policies\DevicePolicy;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Hybrid access control (docs/FGAC_IMPLEMENTATION_PLAN.md §3.3):
        // super_admin bypasses every gate, policy and permission check.
        // Returning null (not false) lets all other users fall through to
        // the normal permission evaluation. This must stay the ONLY
        // hasRole() call in application code — everything else checks
        // permissions via can().
        \Illuminate\Support\Facades\Gate::before(
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
            \Illuminate\Support\Facades\Gate::policy($model, $policy);
        }
    }
}
