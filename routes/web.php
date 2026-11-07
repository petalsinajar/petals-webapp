<?php

use App\Modules\Marketing\Http\Controllers\LandingController;
use App\Modules\Marketing\Http\Controllers\WaitlistController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'show'])->name('landing');
Route::get('/privacy', [LandingController::class, 'privacy'])->name('privacy');
Route::post('/waitlist', [WaitlistController::class, 'store'])->middleware('throttle:waitlist')->name('waitlist.store');
