<?php

use App\Http\Controllers\DoctorWorkbenchController;
use App\Http\Controllers\HospitalDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HospitalDirectoryController::class, 'home'])->name('home');

Route::get('/departments', [HospitalDirectoryController::class, 'departments'])->name('departments.index');
Route::get('/doctors', [HospitalDirectoryController::class, 'doctors'])->name('doctors.index');
Route::get('/doctors/{doctor}', [HospitalDirectoryController::class, 'show'])->whereNumber('doctor')->name('doctors.show');

Route::middleware(['auth', 'active', 'role:DOCTOR'])->group(function (): void {
    Route::get('/doctor/workbench', [DoctorWorkbenchController::class, 'index'])->name('doctor.workbench');
    Route::get('/doctor/schedules/{schedule}', [DoctorWorkbenchController::class, 'show'])->whereNumber('schedule')->name('doctor.schedules.show');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
