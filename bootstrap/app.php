<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SuperAdminMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registered explicitly rather than via withRouting(channels:) so the auth
    // endpoint can be throttled: every private-channel subscription hits it,
    // and each call runs a DevicePolicy check against the database.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'throttle:60,1']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Baseline security response headers on every route, web and api.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            // Legacy role middleware — removed in FGAC Phase 7.
            'admin' => AdminMiddleware::class,
            'superadmin' => SuperAdminMiddleware::class,
            // Spatie hybrid access control (docs/FGAC_IMPLEMENTATION_PLAN.md).
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
