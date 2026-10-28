<?php

use App\Http\Controllers\ToolPageController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

Route::get('/', ToolPageController::class)->name('home');
Route::get('/tools/{slug}', ToolPageController::class)->name('tools.show');

/*
 * Fortify's own sign-up and reset-link routes, again, with a throttle (§18.1).
 * Fortify only lets sign-in be limited; this file loads after its routes,
 * so these replace them under the same URLs and names.
 */
Route::middleware(['guest:'.config('fortify.guard')])->group(function (): void {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');

    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-email')
        ->name('password.email');
});
