<?php

use Illuminate\Support\Facades\Route;
use Modules\AircraftWeights\Http\Controllers\AW_AdminController;

Route::prefix('admin/aircraftweights')
    ->name('aircraftweights.admin.')
    ->middleware(['auth', 'role:admin'])
    ->group(function () {
        Route::get('/',             [AW_AdminController::class, 'index'])->name('index');
        Route::post('/save',        [AW_AdminController::class, 'save'])->name('save');
        Route::post('/delete/{id}', [AW_AdminController::class, 'delete'])->name('delete');
        Route::post('/sync',        [AW_AdminController::class, 'sync'])->name('sync');
        Route::post('/assign',      [AW_AdminController::class, 'assign'])->name('assign');
        Route::post('/fix-lbs',     [AW_AdminController::class, 'fixLbs'])->name('fix_lbs');
    });
