<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundQueryServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;

final class TournamentController extends Controller
{
    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly RoundQueryServiceInterface $roundQuery,
        private readonly ParticipantManagementServiceInterface $participantManagement,
        private readonly MatchQueryServiceInterface $matchQuery,
        private readonly UserDataProviderInterface $userDataProvider,
    ) {}

    public function show(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if ($tournament === null) {
            abort(404);
        }

        if ($tournament->slug !== $identifier && ! Str::isUuid($identifier)) {
            return redirect()->route('tournaments.show', $tournament->slug, 301);
        }

        $hasStarted = $tournament->isInProgress() || $tournament->isFinished();

        $standings = [];
        $topStandings = [];
        $participants = [];

        if ($hasStarted) {
            $standings = $this->tournamentQuery->getStandings($tournament->id);
            $topStandings = array_slice($standings, 0, 10);
        } elseif ($tournament->showParticipants) {
            $participants = $this->tournamentQuery->getParticipants($tournament->id);
        }

        $currentRound = $hasStarted ? $this->roundQuery->getCurrentRound($tournament->id) : null;

        $user = auth()->user();
        $userRegistration = null;
        $canRegister = false;

        if ($user !== null) {
            $registration = $this->participantManagement->findByUserAndTournament(
                (string) $user->id,
                $tournament->id
            );

            $userRegistration = $registration?->toArray();

            $canRegister = $this->tournamentQuery->canUserRegister(
                $tournament->id,
                (string) $user->id,
                $this->userDataProvider->getUserRoles((string) $user->id)
            );
        } else {
            $canRegister = $this->tournamentQuery->canUserRegister(
                $tournament->id,
                null,
                []
            );
        }

        return Inertia::render('Tournaments/Show', [
            'tournament' => $tournament->toArray(),
            'standings' => array_map(fn ($s) => $s->toArray(), $topStandings),
            'participants' => array_map(fn ($p) => $p->toArray(), $participants),
            'currentRound' => $currentRound?->toArray(),
            'userRegistration' => $userRegistration,
            'canRegister' => $canRegister,
        ]);
    }

    public function standings(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if ($tournament === null) {
            abort(404);
        }

        if ($tournament->slug !== $identifier && ! Str::isUuid($identifier)) {
            return redirect()->route('tournaments.standings', $tournament->slug, 301);
        }

        $standings = $this->tournamentQuery->getStandings($tournament->id);

        return Inertia::render('Tournaments/Standings', [
            'tournament' => $tournament->toArray(),
            'standings' => array_map(fn ($s) => $s->toArray(), $standings),
        ]);
    }

    public function rounds(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if ($tournament === null) {
            abort(404);
        }

        if ($tournament->slug !== $identifier && ! Str::isUuid($identifier)) {
            return redirect()->route('tournaments.rounds', $tournament->slug, 301);
        }

        $rounds = $this->roundQuery->getRoundsWithMatches($tournament->id);

        $roundsWithMatches = [];
        foreach ($rounds as $round) {
            $matches = $this->matchQuery->getMatchesForRound($round->id);
            $roundsWithMatches[] = [
                'round' => $round->toArray(),
                'matches' => array_map(fn ($m) => $m->toArray(), $matches),
            ];
        }

        return Inertia::render('Tournaments/Rounds', [
            'tournament' => $tournament->toArray(),
            'rounds' => $roundsWithMatches,
        ]);
    }

    private function findTournament(string $identifier): ?TournamentResponseDTO
    {
        if (Str::isUuid($identifier)) {
            $tournament = $this->tournamentQuery->find($identifier);
            if ($tournament !== null) {
                return $tournament;
            }
        }

        return $this->tournamentQuery->findBySlug($identifier);
    }
}
