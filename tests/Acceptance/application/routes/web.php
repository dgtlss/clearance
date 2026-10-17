<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->name('acceptance.')->group(function () {
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('show');
    Route::get('/orders/{order}/lines/{line}', [OrderController::class, 'line'])->scopeBindings()->name('line');
    Route::post('/orders/{order}/archive', [OrderController::class, 'archive'])->middleware('permission:archive orders')->name('archive');
    if (! getenv('CLEARANCE_OMIT_STAFF')) {
        Route::get('/staff/orders/{order}', [OrderController::class, 'staff'])->middleware('permission:view all orders')->name('staff');
    }
});
