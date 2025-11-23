<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->name('home');

Route::get('/guidelines', function () {
    return view('guidelines');
})->name('guidelines');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Profile routes
    Route::post('/profile/password', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.password.update');
    Route::delete('/profile/account', [\App\Http\Controllers\ProfileController::class, 'deleteAccount'])->name('profile.account.delete');

    // SPA routes - all serve the dashboard view so Vue Router can handle client-side routing
    // These routes are handled by Vue Router on the client side, but Laravel needs to serve
    // the dashboard view for direct navigation (e.g., refreshing the page or typing the URL)
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/users', function () {
        return view('dashboard');
    })->name('users');

    Route::get('/profile', function () {
        return view('dashboard');
    })->name('profile');
});
