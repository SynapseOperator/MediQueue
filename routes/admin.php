<?php

use App\Http\Controllers\AdminResourceController;
use App\Http\Controllers\LifecycleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'role:ADMIN'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/appointments', [LifecycleController::class, 'adminIndex'])->name('appointments.index');
    Route::get('/appointments/{id}', [LifecycleController::class, 'adminShow'])->whereNumber('id')->name('appointments.show');
    Route::post('/appointments/{id}/cancel', [LifecycleController::class, 'adminCancel'])->whereNumber('id')->name('appointments.cancel');
    Route::post('/appointments/{id}/complete', [LifecycleController::class, 'complete'])->whereNumber('id')->name('appointments.complete');
    Route::post('/appointments/{id}/refund', [LifecycleController::class, 'refund'])->whereNumber('id')->name('appointments.refund');
    Route::get('/resources', [AdminResourceController::class, 'index'])->name('resources');
    foreach (['departments' => 'Department', 'doctors' => 'Doctor', 'schedules' => 'Schedule', 'slots' => 'Slot'] as $resource => $method) {
        Route::post('/'.$resource, [AdminResourceController::class, 'store'.$method])->name($resource.'.store');
        Route::put('/'.$resource.'/{id}', [AdminResourceController::class, 'update'.$method])->name($resource.'.update');
    }
});
