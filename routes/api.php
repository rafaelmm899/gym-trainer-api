<?php

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

// Public: no auth:sanctum, no Policy — no actor and no resource yet.
Route::post('register', RegisterController::class)
    ->middleware('throttle:6,1')
    ->name('auth.register');

// Public: the credential check lives in the Action; no Policy (no actor yet).
Route::post('login', LoginController::class)
    ->middleware('throttle:6,1')
    ->name('auth.login');

// Authenticated: each route acts only on the caller — no Policy (never another user's resource).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('logout', LogoutController::class)->name('auth.logout');
    Route::get('user', CurrentUserController::class)->name('auth.user');
});
