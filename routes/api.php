<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobilePlanningController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function (): void {
    Route::post('/login', [MobileAuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.mobile.login');

    Route::middleware('mobile.token')->group(function (): void {
        Route::get('/me', [MobileAuthController::class, 'me'])
            ->name('api.mobile.me');
        Route::post('/logout', [MobileAuthController::class, 'logout'])
            ->name('api.mobile.logout');
        Route::post('/first-password', [MobileAuthController::class, 'updateFirstPassword'])
            ->name('api.mobile.first-password.update');
        Route::get('/planning', MobilePlanningController::class)
            ->name('api.mobile.planning');
    });
});
