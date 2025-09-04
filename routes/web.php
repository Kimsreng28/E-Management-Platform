<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Broadcast;

Route::get('/', function () {
    return view('google');
});

Route::get('/auth/google/redirect', [App\Http\Controllers\Api\AuthController::class, 'googleRedirect'])->name('auth.google.redirect');
Route::get('/api/auth/google/callback', [App\Http\Controllers\Api\AuthController::class, 'googleCallback'])->name('auth.google.callback');

Route::get('/auth/telegram/redirect', [AuthController::class, 'telegramRedirect'])->name('auth.telegram.redirect');
Route::post('/auth/telegram/spa-login', [AuthController::class, 'telegramSpaLogin']);

Route::get('/welcome', function () {
    return view('welcome');
});