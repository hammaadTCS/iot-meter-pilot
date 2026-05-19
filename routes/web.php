<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceDashboardController;
use App\Http\Controllers\DeviceManagementController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Devices
    Route::get('/devices',                     [DeviceManagementController::class, 'index'])->name('devices.index');
    Route::get('/devices/create',              [DeviceManagementController::class, 'create'])->name('devices.create');
    Route::post('/devices',                    [DeviceManagementController::class, 'store'])->name('devices.store');
    Route::get('/devices/{device}/edit',       [DeviceManagementController::class, 'edit'])->name('devices.edit');
    Route::patch('/devices/{device}',          [DeviceManagementController::class, 'update'])->name('devices.update');
    Route::delete('/devices/{device}',         [DeviceManagementController::class, 'destroy'])->name('devices.destroy');
    Route::get('/devices/{device}/dashboard',  [DeviceDashboardController::class, 'show'])->name('devices.dashboard');

    // Backward-compat redirect — remove in Phase 6
    Route::get('/devices/manage', fn() => redirect()->route('devices.index'))->name('devices.manage');

    // User Management — admin+ only
    Route::middleware('admin')->prefix('users')->name('users.')->group(function () {
        Route::get('/',              [UserManagementController::class, 'index'])->name('index');
        Route::get('/create',        [UserManagementController::class, 'create'])->name('create');
        Route::post('/',             [UserManagementController::class, 'store'])->name('store');
        Route::get('/{user}',        [UserManagementController::class, 'show'])->name('show');
        Route::get('/{user}/edit',   [UserManagementController::class, 'edit'])->name('edit');
        Route::patch('/{user}',      [UserManagementController::class, 'update'])->name('update');
    });

    // Super admin only
    Route::middleware('superadmin')->group(function () {
        Route::delete('/users/{user}',       [UserManagementController::class, 'destroy'])->name('users.destroy');
        Route::patch('/users/{user}/role',    [UserManagementController::class, 'updateRole'])->name('users.role');
    });
});

require __DIR__.'/auth.php';
