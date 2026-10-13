<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Controllers\Api\V1\ToolRunController;
use Illuminate\Support\Facades\Route;

/*
 * The JSON API the Next.js frontend talks to (spec §2). Same domain, so it
 * signs in with Sanctum's session cookie; third parties come later with keys.
 */
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('user', CurrentUserController::class)->middleware('auth:sanctum')->name('user');

    Route::get('tools', [ToolController::class, 'index'])->name('tools.index');
    Route::get('tools/{slug}', [ToolController::class, 'show'])->name('tools.show');
    Route::post('tools/{slug}/runs', ToolRunController::class)->name('tools.runs.store');
});
