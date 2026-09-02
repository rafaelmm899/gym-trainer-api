<?php

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Profile\ShowAthleteProfileController;
use App\Http\Controllers\Profile\UpdateAthleteProfileController;
use App\Http\Controllers\Routine\ListRoutinesController;
use App\Http\Controllers\Routine\ShowRoutineController;
use App\Http\Controllers\Routine\StoreRoutineController;
use Illuminate\Support\Facades\Route;

// Public: no auth:sanctum, no Policy — no actor and no resource yet.
Route::post('register', RegisterController::class)
    ->middleware('throttle:6,1')
    ->name('auth.register');

// Public: the credential check lives in the Action; no Policy (no actor yet).
Route::post('login', LoginController::class)
    ->middleware('throttle:6,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    // Each route acts only on the caller — no Policy (never another user's resource).
    Route::post('logout', LogoutController::class)->name('auth.logout');
    Route::get('user', CurrentUserController::class)->name('auth.user');

    // Profile: 1:1 with the user; the Form Requests delegate authorization to AthleteProfilePolicy.
    Route::get('profile', ShowAthleteProfileController::class)->name('profile.show');
    Route::put('profile', UpdateAthleteProfileController::class)->name('profile.update');

    // Routines: the Form Request delegates authorization to RoutinePolicy.
    Route::post('routines', StoreRoutineController::class)->name('routines.store');

    // Reads: the list is scoped to the caller (no Policy); the detail route
    // binds {routine} by uuid and gates on RoutinePolicy::view.
    Route::get('routines', ListRoutinesController::class)->name('routines.list');
    Route::get('routines/{routine}', ShowRoutineController::class)
        ->whereUuid('routine')
        ->can('view', 'routine')
        ->name('routines.show');
});
