<?php

use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

// Public: no auth:sanctum, no Policy — no actor and no resource yet.
Route::post('register', RegisterController::class)
    ->middleware('throttle:6,1')
    ->name('auth.register');
