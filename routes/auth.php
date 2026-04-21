<?php

use App\Http\Controllers\Auth\AuthController as ApiAuthController;
use App\Http\Controllers\Settings\PasswordController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('register', fn () => Inertia::render('Auth/Register'))->name('register');
    Route::get('login', fn () => Inertia::render('Auth/Login'))->name('login');
});

Route::middleware("auth:sanctum")->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [ApiAuthController::class, 'logout'])->name('logout');
});
