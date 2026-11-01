<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tournaments\Http\Controllers\GuestCancellationController;
use Modules\Tournaments\Http\Controllers\GuestConfirmationController;
use Modules\Tournaments\Http\Controllers\MatchReportController;
use Modules\Tournaments\Http\Controllers\MyTournamentsController;
use Modules\Tournaments\Http\Controllers\TournamentCheckInController;
use Modules\Tournaments\Http\Controllers\TournamentController;
use Modules\Tournaments\Http\Controllers\TournamentListController;
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

    // Guest email confirmation (public): the page only shows, the button confirms
    Route::get('/confirmar/{token}', [GuestConfirmationController::class, 'show'])
        ->middleware('throttle:tournaments-guest-confirmation')
        ->name('confirm-registration');
    Route::post('/confirmar/{token}', [GuestConfirmationController::class, 'store'])
        ->middleware('throttle:tournaments-guest-confirmation')
        ->name('confirm-registration.store');

    // A player's side of their match result (JSON from the tournament page). Behind `auth`,
    // so the core's "email verification required" rule applies. Limited per user
    Route::prefix('partidas/{matchId}')
        ->whereUuid('matchId')
        ->middleware(['auth', 'throttle:tournaments-match-report'])
        ->name('matches.')
        ->group(function (): void {
            Route::post('/resultado', [MatchReportController::class, 'report'])->name('report');
            Route::post('/confirmar', [MatchReportController::class, 'confirm'])->name('confirm');
            Route::post('/discutir', [MatchReportController::class, 'dispute'])->name('dispute');
        });

    // My tournaments (authenticated users only)
    Route::get('/mis-torneos', MyTournamentsController::class)
        ->middleware('auth')
        ->name('my-tournaments');

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

    // Open to guests; a signed-in user checks in as themselves, so the core's
    // "email verification required" rule applies to them. Limited per IP
    Route::post('/{slug}/check-in', [TournamentCheckInController::class, 'store'])
        ->where('slug', '[a-z0-9-]+')
        ->middleware(['throttle:tournaments-public-check-in', 'email.verified-if-required'])
        ->name('check-in.store');
});

// Registration routes (without /api prefix)
Route::prefix('torneos/{tournamentId}')
    ->whereUuid('tournamentId')
    ->group(function (): void {
        // Public: get registration status
        Route::get('/inscripcion', [TournamentRegistrationController::class, 'show'])
            ->name('tournaments.registration.show');

        // Public: register (authenticated user or guest if allowed). A signed-in user
        // registers as themselves, so the core's "email verification required" rule applies
        // Guests (no session) are limited per IP
        Route::post('/inscripcion', [TournamentRegistrationController::class, 'store'])
            ->middleware(['throttle:tournaments-guest-registration', 'email.verified-if-required'])
            ->name('tournaments.registration.store');

        // Only authenticated users can withdraw
        Route::middleware('auth')->group(function (): void {
            Route::delete('/inscripcion', [TournamentRegistrationController::class, 'destroy'])
                ->name('tournaments.registration.destroy');
        });
    });
