<?php

use App\Http\Controllers\AdminResourceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'role:ADMIN'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/resources', [AdminResourceController::class, 'index'])->name('resources');
    foreach (['departments' => 'Department', 'doctors' => 'Doctor', 'schedules' => 'Schedule', 'slots' => 'Slot'] as $resource => $method) {
        Route::post('/'.$resource, [AdminResourceController::class, 'store'.$method])->name($resource.'.store');
        Route::put('/'.$resource.'/{id}', [AdminResourceController::class, 'update'.$method])->name($resource.'.update');
    }
});
