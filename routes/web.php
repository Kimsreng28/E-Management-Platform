<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::get('/', function () {
    return view('google');
});

Route::get('/auth/google/redirect', [App\Http\Controllers\Api\AuthController::class, 'googleRedirect'])->name('auth.google.redirect');
Route::get('/api/auth/google/callback', [App\Http\Controllers\Api\AuthController::class, 'googleCallback'])->name('auth.google.callback');

Route::get('/auth/telegram/redirect', [AuthController::class, 'telegramRedirect'])->name('auth.telegram.redirect');
Route::get('/auth/telegram/callback', [AuthController::class, 'telegramCallback'])->name('auth.telegram.callback');
