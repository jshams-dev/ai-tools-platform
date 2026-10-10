<?php

use App\Http\Controllers\ToolPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', ToolPageController::class)->name('home');
Route::get('/tools/{slug}', ToolPageController::class)->name('tools.show');
