<?php

use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Profile\ShowAthleteProfileController;
use App\Http\Controllers\Profile\UpdateAthleteProfileController;
use Illuminate\Support\Facades\Route;

// Public: no auth:sanctum, no Policy — no actor and no resource yet.
Route::post('register', RegisterController::class)
    ->middleware('throttle:6,1')
    ->name('auth.register');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('profile', ShowAthleteProfileController::class)->name('profile.show');
    Route::put('profile', UpdateAthleteProfileController::class)->name('profile.update');
});
