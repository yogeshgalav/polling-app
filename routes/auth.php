<?php

use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Api\AuthController as ApiAuthController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('register', fn () => Inertia::render('Auth/Register'))->name('register');
    Route::get('login', fn () => Inertia::render('Auth/Login'))->name('login');
});

Route::middleware("auth:sanctum")->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('logout', [ApiAuthController::class, 'logout'])->name('logout');
});
