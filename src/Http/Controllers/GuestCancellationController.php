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

        if (!$participant instanceof \Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.invalid_token'));
        }

        $tournament = $this->tournamentQuery->find($participant->tournamentId);

        if (!$tournament instanceof \Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO) {
            return redirect()->route('tournaments.index')
                ->with('error', __('tournaments::messages.errors.tournament_not_found'));
        }

        // Same rule withdrawByToken() enforces (Tournament::canWithdraw()), with the reason the page shows
        $cancelBlockedReason = match (true) {
            ! $participant->status->isActive() => 'already_cancelled',
            $tournament->status === TournamentStatus::Cancelled => 'tournament_cancelled',
            ! $tournament->canWithdraw => 'tournament_started',
            default => null,
        };

        return Inertia::render('Tournaments/CancelRegistration', [
            'participant' => $participant->toArray(),
            'tournament' => [
                'id' => $tournament->id,
                'name' => $tournament->name,
                'eventDate' => $this->events->findById($tournament->eventId)?->startDate->format('c'),
            ],
            'token' => $token,
            'canCancel' => $cancelBlockedReason === null,
            'cancelBlockedReason' => $cancelBlockedReason,
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
        } catch (CannotWithdrawException $e) {
            return redirect()->route('tournaments.index')
                ->with('error', $e->eventHasStarted()
                    ? __('tournaments::messages.errors.cannot_cancel_started')
                    : __('tournaments::messages.errors.cannot_withdraw'));
        }
    }
}
