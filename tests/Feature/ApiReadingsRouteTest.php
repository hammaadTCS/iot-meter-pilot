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
}
