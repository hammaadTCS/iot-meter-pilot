<?php

namespace Tests\Feature;

use App\Http\Controllers\DeviceReadingController;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiReadingsRouteTest extends TestCase
{
    public function test_dashboard_readings_endpoint_uses_device_reading_controller(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/devices/123/readings', 'GET')
        );

        $this->assertSame(
            DeviceReadingController::class.'@index',
            $route->getActionName()
        );
    }

    /**
     * chart, readings and snapshot were the only unthrottled routes in this
     * group. They are also the heaviest — they scan raw readings — so on the
     * public internet they were the cheapest way to exhaust the database.
     */
    public function test_every_readings_endpoint_is_throttled(): void
    {
        $paths = [
            'api/devices/{device}/readings/consumption',
            'api/devices/{device}/consumption/daily',
            'api/devices/{device}/readings/aggregate',
            'api/devices/{device}/readings/chart',
            'api/devices/{device}/readings',
            'api/devices/{id}/snapshot',
        ];

        foreach ($paths as $path) {
            $route = collect(app('router')->getRoutes()->getRoutes())
                ->first(fn ($r) => $r->uri() === $path);

            $this->assertNotNull($route, "Route {$path} is missing.");

            $this->assertTrue(
                collect($route->gatherMiddleware())
                    ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
                "Route {$path} carries no throttle middleware."
            );
        }
    }
}
