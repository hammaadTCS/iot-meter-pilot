<?php

use App\Http\Controllers\MeterDashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| For this pilot, the home page is the live meter dashboard.
|
*/

Route::get('/', [MeterDashboardController::class, 'show']);
