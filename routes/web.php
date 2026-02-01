<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tournaments\Http\Controllers\TournamentCheckInController;
use Modules\Tournaments\Http\Controllers\TournamentController;
use Modules\Tournaments\Http\Controllers\TournamentListController;
use Modules\Tournaments\Http\Controllers\GuestCancellationController;
use Modules\Tournaments\Http\Controllers\TournamentRegistrationController;

/*
|--------------------------------------------------------------------------
| Tournaments Module Web Routes
|--------------------------------------------------------------------------
*/

// Public tournament pages
Route::prefix('torneos')->name('tournaments.')->group(function (): void {
    // Guest cancellation routes (public, no auth required)
    Route::get('/cancelar/{token}', [GuestCancellationController::class, 'show'])
        ->name('cancel-confirmation');
    Route::delete('/cancelar/{token}', [GuestCancellationController::class, 'destroy'])
        ->name('cancel-by-token');

    // Index route MUST be before {slug} to avoid being matched as a slug
    Route::get('/', [TournamentListController::class, 'index'])
        ->name('index');

    Route::get('/{slug}', [TournamentController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('show');

    Route::get('/{slug}/clasificacion', [TournamentController::class, 'standings'])
        ->where('slug', '[a-z0-9-]+')
        ->name('standings');

    Route::get('/{slug}/rondas', [TournamentController::class, 'rounds'])
        ->where('slug', '[a-z0-9-]+')
        ->name('rounds');

    // Public check-in routes
    Route::get('/{slug}/check-in', [TournamentCheckInController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('check-in.show');

    Route::post('/{slug}/check-in', [TournamentCheckInController::class, 'store'])
        ->where('slug', '[a-z0-9-]+')
        ->name('check-in.store');
});

// Registration routes (without /api prefix)
Route::prefix('torneos/{tournamentId}')
    ->whereUuid('tournamentId')
    ->group(function (): void {
        // Public: get registration status
        Route::get('/inscripcion', [TournamentRegistrationController::class, 'show'])
            ->name('tournaments.registration.show');

        // Public: register (authenticated user or guest if allowed)
        Route::post('/inscripcion', [TournamentRegistrationController::class, 'store'])
            ->name('tournaments.registration.store');

        // Only authenticated users can withdraw
        Route::middleware('auth')->group(function (): void {
            Route::delete('/inscripcion', [TournamentRegistrationController::class, 'destroy'])
                ->name('tournaments.registration.destroy');
        });
    });
