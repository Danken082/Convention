<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\RegistrationController;

Route::get('/', [RegistrationController::class, 'create'])->name('registrations.create');
Route::post('/registrations', [RegistrationController::class, 'store'])->name('registrations.store');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminController::class, 'login'])->name('login');
    Route::post('/login', [AdminController::class, 'authenticate'])
        ->middleware('throttle:5,1')
        ->name('authenticate');

    Route::middleware('admin.access')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
        Route::get('/google-sheets/connect', [AdminController::class, 'connectGoogleSheets'])
            ->middleware('throttle:5,1')
            ->name('google-sheets.connect');
        Route::get('/google-sheets/callback', [AdminController::class, 'googleSheetsCallback'])
            ->name('google-sheets.callback');
        Route::post('/google-sheets/sync', [AdminController::class, 'syncGoogleSheets'])->name('sync');
        Route::get('/registrations.xlsx', [AdminController::class, 'export'])->name('export');
        Route::get('/registrations/{registration}/proof', [AdminController::class, 'proof'])
            ->name('proof');
    });
});
