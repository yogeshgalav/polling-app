<?php

use App\Http\Controllers\Internal\RunDeployTasksController;
use App\Http\Controllers\Polls\Admin\PollApiController;
use App\Http\Controllers\Polls\Admin\PollPageController;
use App\Http\Controllers\Polls\PublicPollController;
use App\Http\Controllers\Settings\ProfileController;
use App\Models\Poll;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPollController::class, 'index'])->name('polls.index');
Route::redirect('/polls', '/');
Route::get('/polls/feed', [PublicPollController::class, 'feed'])->name(
    'polls.feed',
);
Route::get('/polls/{poll:slug}', [PublicPollController::class, 'show'])->name(
    'polls.show',
);
Route::post('/polls/{poll}/vote', [PublicPollController::class, 'vote'])->name(
    'polls.vote',
)->middleware('throttle:poll-vote');

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::prefix('api')->name('api.')->group(function () {
            Route::post('polls', [PollApiController::class, 'store'])->name(
                'polls.store',
            );
            Route::put('polls/{poll:slug}', [
                PollApiController::class,
                'update',
            ])->name('polls.update');
            Route::delete('polls/{poll:slug}', [
                PollApiController::class,
                'destroy',
            ])->name('polls.destroy');
        });

        Route::get('polls', [PollPageController::class, 'index'])
            ->name('polls.index')
            ->can('viewAny', Poll::class);

        Route::get('polls/create', [PollPageController::class, 'create'])
            ->name('polls.create')
            ->can('create', Poll::class);

        Route::get('polls/{poll}/results', [
            PollPageController::class,
            'results',
        ])
            ->name('polls.results')
            ->can('viewResults', 'poll');

        Route::get('polls/{poll}/edit', [PollPageController::class, 'edit'])
            ->name('polls.edit')
            ->can('update', 'poll');
    });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name(
        'profile.edit',
    );
    Route::patch('/profile', [ProfileController::class, 'update'])->name(
        'profile.update',
    );
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name(
        'profile.destroy',
    );
});

if (app()->isLocal()) {
    Route::post('/_internal/run-deploy-tasks', RunDeployTasksController::class);
}

require __DIR__.'/auth.php';
