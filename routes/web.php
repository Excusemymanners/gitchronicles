<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Repository\AssistantController;
use App\Http\Controllers\Repository\DiagramsController;
use App\Http\Controllers\Repository\DocsController;
use App\Http\Controllers\Repository\MediaController;
use App\Http\Controllers\Repository\TimelineController;
use App\Http\Controllers\RepositoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', [RepositoryController::class, 'index'])->name('home');

// Browsing is public. "store" is public too: the controller sends guests to log in
// before it creates anything. Only "destroy" needs an account.
Route::resource('repositories', RepositoryController::class)
    ->only(['store', 'show', 'destroy'])
    ->middlewareFor('store', 'throttle:repositories')
    ->middlewareFor('destroy', 'auth');

// The other tabs of a repository page („Prezentare” is repositories.show above). All public, like the catalog.
Route::prefix('repositories/{repository}')->name('repositories.')->group(function () {
    // „Documentație”: the overview and one page per module (Faza 6).
    Route::get('docs', DocsController::class)->name('docs');

    // „Diagrame”: the C4 architecture diagrams (Faza 7).
    Route::get('diagrams', DiagramsController::class)->name('diagrams');

    // „Asistent”: questions about the code, answered with cited files and lines (Faza 8).
    Route::get('assistant', AssistantController::class)->name('assistant');

    // „Istoric”: the commits, the processing runs and the time travel (Faza 9).
    Route::get('timeline', TimelineController::class)->name('timeline');

    // „Video”: the presentation video and slides (bonus).
    Route::get('media', MediaController::class)->name('media');
});

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
