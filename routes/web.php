<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\RepositoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RepositoryController::class, 'index'])->name('home');

// Browsing is public. "store" is public too: the controller sends guests to log in
// before it creates anything. Only "destroy" needs an account.
Route::resource('repositories', RepositoryController::class)
    ->only(['store', 'show', 'destroy'])
    ->middlewareFor('store', 'throttle:repositories')
    ->middlewareFor('destroy', 'auth');

// „Reîncearcă” after a failed processing: it starts a new clone, so it shares the add limit.
Route::post('/repositories/{repository}/retry', [RepositoryController::class, 'retry'])
    ->middleware(['auth', 'throttle:repositories'])
    ->name('repositories.retry');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
