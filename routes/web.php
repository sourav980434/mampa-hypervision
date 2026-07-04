<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\VMController;
use App\Http\Controllers\PortMappingController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\StorageController;
use App\Http\Controllers\USBController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    
    // VM Management Routes
    Route::get('/vms/{uuid}', [VMController::class, 'show'])->name('vms.show');
    Route::post('/vms/{uuid}/start', [VMController::class, 'start'])->name('vms.start');
    Route::post('/vms/{uuid}/stop', [VMController::class, 'stop'])->name('vms.stop');
    Route::post('/vms/{uuid}/force-stop', [VMController::class, 'forceStop'])->name('vms.force-stop');
    Route::post('/vms/{uuid}/reboot', [VMController::class, 'reboot'])->name('vms.reboot');
    Route::post('/vms/{uuid}/suspend', [VMController::class, 'suspend'])->name('vms.suspend');
    Route::post('/vms/{uuid}/resume', [VMController::class, 'resume'])->name('vms.resume');
    Route::post('/vms/{uuid}/metadata', [VMController::class, 'updateMetadata'])->name('vms.metadata');
    Route::get('/vms/{uuid}/stats', [VMController::class, 'getStats'])->name('vms.stats');
    Route::get('/vms/{uuid}/execution-plan/{action}', [VMController::class, 'getExecutionPlan'])->name('vms.execution-plan');
    Route::post('/vms', [VMController::class, 'store'])->name('vms.store');
    Route::delete('/vms/{uuid}', [VMController::class, 'destroy'])->name('vms.destroy');
    Route::post('/vms/{uuid}/update', [VMController::class, 'update'])->name('vms.update');
    Route::get('/api/storage/pools', [StorageController::class, 'getPools'])->name('storage.pools');
    Route::get('/api/storage/isos', [StorageController::class, 'getISOs'])->name('storage.isos');

    // Port Forwarding Routes
    Route::get('/port-forwarding', [PortMappingController::class, 'index'])->name('port-forwarding.index');
    Route::post('/port-forwarding', [PortMappingController::class, 'store'])->name('port-forwarding.store');
    Route::post('/port-forwarding/{id}/toggle', [PortMappingController::class, 'toggle'])->name('port-forwarding.toggle');
    Route::delete('/port-forwarding/{id}', [PortMappingController::class, 'destroy'])->name('port-forwarding.destroy');
    Route::get('/port-forwarding/execution-plan/{action}', [PortMappingController::class, 'getExecutionPlan'])->name('port-forwarding.execution-plan');
    Route::post('/port-forwarding/reapply', [PortMappingController::class, 'reapply'])->name('port-forwarding.reapply');
    Route::post('/port-forwarding/{id}/test', [PortMappingController::class, 'test'])->name('port-forwarding.test');

    // Audit Logs Route
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    // USB Management Routes
    Route::get('/usb-devices', [USBController::class, 'index'])->name('usb-devices.index');
    Route::get('/api/usb/devices', [USBController::class, 'getDevices'])->name('api.usb.devices');
    Route::get('/api/usb/attached', [USBController::class, 'getAttached'])->name('api.usb.attached');
    Route::post('/api/usb/attach', [USBController::class, 'attach'])->name('api.usb.attach');
    Route::post('/api/usb/detach', [USBController::class, 'detach'])->name('api.usb.detach');
    Route::get('/api/usb/storage', [USBController::class, 'getStorage'])->name('api.usb.storage');
    Route::post('/api/usb/storage/mount', [USBController::class, 'mount'])->name('api.usb.mount');
    Route::post('/api/usb/storage/unmount', [USBController::class, 'unmount'])->name('api.usb.unmount');

    // Profile Settings
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
