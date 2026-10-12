<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\EventQueryServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;

final class GuestCancellationController extends Controller
{
    public function __construct(
        private readonly ParticipantManagementServiceInterface $participantService,
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly EventQueryServiceInterface $events,
    ) {}

    /**
     * Show the cancellation confirmation page.
     */
    public function show(string $token): Response|RedirectResponse
    {
        $participant = $this->participantService->findByToken($token);

        if ($participant === null) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.invalid_token'));
        }

        $tournament = $this->tournamentQuery->find($participant->tournamentId);

        if ($tournament === null) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.tournament_not_found'));
        }

        return Inertia::render('Tournaments/CancelRegistration', [
            'participant' => $participant->toArray(),
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'eventDate' => $this->events->findById($tournament->eventId)?->startDate->format('c'),
            ],
            'token' => $token,
            // Same rules withdrawByToken() enforces
            'canCancel' => $participant->status->isActive()
                && ! in_array($tournament->status, [TournamentStatus::InProgress, TournamentStatus::Finished], true),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('tournaments::messages.seo.cancel_registration_title'),
                noindex: true,
            ))->toArray(),
        ]);
    }

    /**
     * Cancel a registration by token.
     */
    public function destroy(string $token): RedirectResponse
    {
        try {
            $this->participantService->withdrawByToken($token);

            return redirect()->route('tournaments.index')
                ->with('success', __('tournaments::messages.cancellation.success'));
        } catch (ParticipantNotFoundException) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.invalid_token'));
        } catch (CannotWithdrawException) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.cannot_withdraw'));
        }
    }
}
