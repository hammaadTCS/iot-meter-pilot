<?php
// =============================================================================
// routes/api.php
// =============================================================================
//
// HOW TO CREATE THIS FILE (if it doesn't exist yet):
// ---------------------------------------------------
// Run this ONE command in your project root terminal:
//
//   php artisan install:api
//
// That command will:
//   1. Create this file at  routes/api.php
//   2. Add  ->withApiRouting()  to bootstrap/app.php  (Laravel 11)
//   3. Optionally publish the Sanctum migration (you can skip it)
//
// If you are on Laravel 10 or older, the file was created automatically
// when you made the project. Just add your routes below.
//
// WHY api.php instead of web.php?
// --------------------------------
// Routes in api.php:
//   ✓ Are automatically prefixed with /api  (so the URL is /api/devices/...)
//   ✓ Use the "api" middleware group — CSRF protection is DISABLED,
//     which is correct for AJAX/JSON endpoints called by JavaScript
//   ✓ Are stateless by default (no session cookies)
//
// Routes in web.php use the "web" middleware group which enforces
// CSRF tokens. Your dashboard's fetch() calls do NOT send a CSRF token,
// so they would get a 419 error if placed in web.php.
//
// =============================================================================

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DeviceReadingController;
use App\Http\Controllers\Api\DeviceController;

// Public health check
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// Protected routes requiring authentication. Permission slugs (hybrid FGAC):
// api.devices.read / api.readings.read are built-ins every bundle carries;
// api.devices.write is a separate grant (field_engineer, or direct).
// Ownership scoping and the meter.* section checks live in the controllers.
Route::middleware('auth:sanctum')->group(function () {
    // Device Management
    Route::get('/devices', [DeviceController::class, 'index'])->middleware('permission:api.devices.read');
    Route::post('/devices', [DeviceController::class, 'store'])->middleware('permission:api.devices.write');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->middleware('permission:api.devices.read');
    Route::get('/devices/{device}/status', [DeviceController::class, 'status'])->middleware('permission:api.devices.read');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->middleware('permission:api.devices.write');

    // Device Readings — the named sub-routes must be registered before the base
    // readings route so Laravel doesn't resolve "chart"/"consumption" as a
    // {device} parameter. Consumption is throttled (it can scan a window).
    Route::middleware('permission:api.readings.read')->group(function () {
        Route::get('/devices/{device}/readings/consumption', [DeviceReadingController::class, 'consumption'])
            ->middleware('throttle:120,1');
        Route::get('/devices/{device}/consumption/daily', [DeviceReadingController::class, 'dailyConsumption'])
            ->middleware('throttle:60,1'); // per-day units + monthly total report (aggregates only)
        Route::get('/devices/{device}/readings/chart',  [DeviceReadingController::class, 'chart']);
        Route::get('/devices/{device}/readings',        [DeviceReadingController::class, 'index']);
        Route::get('/devices/{id}/snapshot',            [DeviceController::class, 'readings']);
    });
});
