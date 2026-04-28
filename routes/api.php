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

// -----------------------------------------------------------------------------
// Meter Dashboard — Readings API
// -----------------------------------------------------------------------------
//
// Used by the JavaScript in meter-dashboard.blade.php
//
// Full URL after registration: GET /api/devices/{device}/readings
//
// Examples:
//   /api/devices/1/readings?range=1h           → all readings from last 1 hour
//   /api/devices/1/readings?range=6h           → last 6 hours
//   /api/devices/1/readings?range=24h          → rolling 24 hours
//   /api/devices/1/readings?range=today        → since midnight today
//   /api/devices/1/readings?range=7d           → last 7 days
//   /api/devices/1/readings?range=1h&after=42  → only rows with id > 42
//
// {device} uses Laravel Route Model Binding — it automatically fetches
// the Device model from the database using the ID in the URL.
// -----------------------------------------------------------------------------
use App\Http\Controllers\Api\DeviceController;

Route::get('/devices', [DeviceController::class, 'index']);
Route::post('/devices', [DeviceController::class, 'store']);
Route::get('/devices/{device}', [DeviceController::class, 'show']);
Route::get('/devices/{device}/status', [DeviceController::class, 'status']);
Route::delete('/devices/{device}', [DeviceController::class, 'destroy']);
Route::get('/devices/{device}/readings', [DeviceReadingController::class, 'index']);
Route::get('/devices/{id}/snapshot', [DeviceController::class, 'readings']);
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');
