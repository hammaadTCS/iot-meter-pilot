<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceDashboardController;
use App\Http\Controllers\DeviceManagementController;
use App\Http\Controllers\MeterAlertSettingsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // In-app notification bell
    Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    // Alerts console — visibility scoped inside AlertEvent::visibleTo()
    Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');

    // Notification preferences
    Route::get('/settings/notifications',   [NotificationPreferenceController::class, 'edit'])->name('settings.notifications.edit');
    Route::patch('/settings/notifications', [NotificationPreferenceController::class, 'update'])->name('settings.notifications.update');

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
    Route::get('/devices/{device}/alerts',      [MeterAlertSettingsController::class, 'edit'])->name('devices.alerts.edit');
    Route::patch('/devices/{device}/alerts',    [MeterAlertSettingsController::class, 'update'])->name('devices.alerts.update');

    // Backward-compat redirect — remove in Phase 6
    Route::get('/devices/manage', fn() => redirect()->route('devices.index'))->name('devices.manage');

    // User Management — permission-gated (hybrid FGAC): view_list opens the
    // area, create/edit/profile need their own slugs. Super admins pass
    // everything via Gate::before.
    Route::middleware('permission:users.view_list')->prefix('users')->name('users.')->group(function () {
        Route::get('/',              [UserManagementController::class, 'index'])->name('index');
        Route::get('/create',        [UserManagementController::class, 'create'])->name('create')->middleware('permission:users.create');
        Route::post('/',             [UserManagementController::class, 'store'])->name('store')->middleware('permission:users.create');
        Route::get('/{user}',        [UserManagementController::class, 'show'])->name('show')->middleware('permission:users.view_profile');
        Route::get('/{user}/edit',   [UserManagementController::class, 'edit'])->name('edit')->middleware('permission:users.edit');
        Route::patch('/{user}',      [UserManagementController::class, 'update'])->name('update')->middleware('permission:users.edit');
    });

    // Account deletion stays super-admin only (plan: users.delete is never
    // delegated).
    Route::middleware('role:super_admin')->group(function () {
        Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
    });

    // Access management (hybrid FGAC) — permission-gated: super admins pass
    // via Gate::before; granting users.manage_permissions to anyone else is
    // deliberate delegation of that authority.
    Route::middleware('permission:users.manage_permissions')->group(function () {
        Route::get('/users/{user}/permissions',   [PermissionController::class, 'show'])->name('users.permissions.show');
        Route::patch('/users/{user}/permissions', [PermissionController::class, 'update'])->name('users.permissions.update');
        Route::post('/users/{user}/permissions/detach-bundle', [PermissionController::class, 'detachBundle'])->name('users.permissions.detach');
    });
});

require __DIR__.'/auth.php';
