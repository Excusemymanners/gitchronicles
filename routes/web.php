<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Repository\AssistantController;
use App\Http\Controllers\Repository\AssistantQuestionController;
use App\Http\Controllers\Repository\DiagramsController;
use App\Http\Controllers\Repository\DocsController;
use App\Http\Controllers\Repository\DownloadReadmeController;
use App\Http\Controllers\Repository\MediaController;
use App\Http\Controllers\Repository\MediaFileController;
use App\Http\Controllers\Repository\TimelineController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
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
    // „Documentație”: the overview, the generated README and one page per module (Faza 6),
    // plus the generated README as a file. A slug has no dot, so "readme.md" never reaches DocsController.
    Route::get('docs/readme.md', DownloadReadmeController::class)->name('docs.readme');
    Route::get('docs/{slug?}', DocsController::class)->where('slug', '[a-z0-9-]+')->name('docs');

    // „Diagrame”: the C4 architecture diagrams (Faza 7).
    Route::get('diagrams', DiagramsController::class)->name('diagrams');

    // „Asistent”: questions about the code, answered with cited files and lines (Faza 8).
    Route::get('assistant', AssistantController::class)->name('assistant');
    Route::post('assistant', AssistantQuestionController::class)->middleware('throttle:assistant')->name('assistant.ask');

    // „Istoric”: the commits, the processing runs and the time travel (Faza 9).
    Route::get('timeline', TimelineController::class)->name('timeline');

    // „Video”: the presentation video and the slides (bonus), and the routes that serve their files
    // from the private disk (the video with Range support, so the player can seek).
    Route::get('media', MediaController::class)->name('media');
    Route::get('media/video', [MediaFileController::class, 'video'])->name('media.video');
    Route::get('media/poster', [MediaFileController::class, 'poster'])->name('media.poster');
    Route::get('media/captions', [MediaFileController::class, 'captions'])->name('media.captions');
    Route::get('media/download/{format}', [MediaFileController::class, 'download'])
        ->whereIn('format', ['mp4', 'pdf', 'pptx'])
        ->name('media.download');
});

// „Reîncearcă” after a failed processing: it starts a new clone, so it shares the add limit.
Route::post('/repositories/{repository}/retry', [RepositoryController::class, 'retry'])
    ->middleware(['auth', 'throttle:repositories'])
    ->name('repositories.retry');

// „Verifică actualizări” on a ready repository: it starts a fetch, so it shares the add limit too (Faza 9).
Route::post('/repositories/{repository}/check-updates', [RepositoryController::class, 'checkUpdates'])
    ->middleware(['auth', 'throttle:repositories'])
    ->name('repositories.check-updates');

// GitHub calls this after every push to a repository that has the webhook set up (Faza 9).
// It has no CSRF token (bootstrap/app.php): the request is trusted only by its signature.
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:webhooks')
    ->name('webhooks.github');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
