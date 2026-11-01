<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Application\DTOs\Response\EventResponseDTO;
use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\DTOs\SeoScheduleDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Services\EventQueryServiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectLinksInterface;
use App\Application\Surveys\DTOs\SurveyLinkDTO;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\GuestConfirmationQueryInterface;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\RoundQueryServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Http\CheckInWindow;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;

final class TournamentController extends Controller
{
    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly RoundQueryServiceInterface $roundQuery,
        private readonly ParticipantManagementServiceInterface $participantManagement,
        private readonly MatchQueryServiceInterface $matchQuery,
        private readonly UserDataProviderInterface $userDataProvider,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly EventQueryServiceInterface $events,
        private readonly GuestConfirmationQueryInterface $guestConfirmation,
        private readonly CheckInWindow $checkInWindow,
        private readonly SurveySubjectLinksInterface $surveyLinks,
    ) {}

    public function show(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if (! $tournament instanceof TournamentResponseDTO) {
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
        $canCheckIn = false;

        if ($user !== null) {
            $registration = $this->participantManagement->findByUserAndTournament(
                (string) $user->id,
                $tournament->id
            );

            $userRegistration = $registration?->toArray();

            // The rule of the check-in itself: self check-in open now and the registration may check in
            $canCheckIn = $registration instanceof ParticipantResponseDTO
                && $this->checkInWindow->calculate($tournament, $tournament->eventStartsAt)['status'] === 'open'
                && $this->participantManagement->canCheckIn($registration->status, $tournament->requiresManualConfirmation);

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
            // Other people's registrations: never their emails
            'participants' => array_map(fn ($p) => $p->toPublicArray(), $participants),
            'currentRound' => $currentRound?->toArray(),
            'userRegistration' => $userRegistration,
            'canRegister' => $canRegister,
            'canCheckIn' => $canCheckIn,
            // Their own match of the current round, with the report only its players see
            'myMatch' => $user !== null && $hasStarted
                ? $this->matchQuery->findPlayerMatch($tournament->id, (string) $user->id)?->toArray()
                : null,
            'guestConfirmation' => $this->guestConfirmation->forTournament($tournament->id),
            // Its surveys, through the core: a surveys module answers, and without one nothing shows
            'surveyLinks' => $user !== null ? $this->surveyLinksFor($tournament->id, (string) $user->id) : null,
            'seo' => $this->showSeo($tournament),
        ]);
    }

    /**
     * What the person reaches from the page: the tournament's surveys page when they organize it,
     * and the surveys sent to them.
     *
     * @return array{manageUrl: string|null, surveys: list<array{title: string, url: string, action: string}>}|null
     */
    private function surveyLinksFor(string $tournamentId, string $userId): ?array
    {
        $links = $this->surveyLinks->forSubject(TournamentSurveySubjectSource::TYPE, $tournamentId, $userId);
        if ($links->isEmpty()) {
            return null;
        }

        return [
            'manageUrl' => $links->manageUrl,
            'surveys' => array_map(static fn (SurveyLinkDTO $link): array => [
                'title' => $link->title,
                'url' => $link->url,
                'action' => $link->action->value,
            ], $links->surveys),
        ];
    }

    public function standings(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if (! $tournament instanceof TournamentResponseDTO) {
            abort(404);
        }

        if ($tournament->slug !== $identifier && ! Str::isUuid($identifier)) {
            return redirect()->route('tournaments.standings', $tournament->slug, 301);
        }

        $standings = $this->tournamentQuery->getStandings($tournament->id);

        return Inertia::render('Tournaments/Standings', [
            'tournament' => $tournament->toArray(),
            'standings' => array_map(fn ($s) => $s->toArray(), $standings),
            'seo' => $this->subpageSeo($tournament, 'standings'),
        ]);
    }

    public function rounds(string $identifier): Response|RedirectResponse
    {
        $tournament = $this->findTournament($identifier);

        if (! $tournament instanceof TournamentResponseDTO) {
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
            'seo' => $this->subpageSeo($tournament, 'rounds'),
        ]);
    }

    /**
     * The tournament's own image and text, with the date and place of the event it belongs to.
     *
     * @return array<string, mixed>
     */
    private function showSeo(TournamentResponseDTO $tournament): array
    {
        $event = $this->events->findById($tournament->eventId);

        return $this->seo->forPage(new SeoPageDTO(
            title: $tournament->name,
            description: $tournament->description ?? $event?->description,
            imagePublicId: $tournament->imagePublicId ?? $event?->imagePublicId,
            schedule: $event instanceof EventResponseDTO
                ? new SeoScheduleDTO($event->startDate, $event->endDate, $event->location)
                : null,
        ))->toArray();
    }

    /**
     * @param  'standings'|'rounds'  $page
     * @return array<string, mixed>
     */
    private function subpageSeo(TournamentResponseDTO $tournament, string $page): array
    {
        $event = $this->events->findById($tournament->eventId);

        return $this->seo->forPage(new SeoPageDTO(
            title: (string) __("tournaments::messages.seo.{$page}_title", ['name' => $tournament->name]),
            description: (string) __("tournaments::messages.seo.{$page}_description", ['name' => $tournament->name]),
            imagePublicId: $tournament->imagePublicId ?? $event?->imagePublicId,
        ))->toArray();
    }

    private function findTournament(string $identifier): ?TournamentResponseDTO
    {
        if (Str::isUuid($identifier)) {
            $tournament = $this->tournamentQuery->find($identifier);
            if ($tournament instanceof TournamentResponseDTO) {
                return $tournament;
            }
        }

        return $this->tournamentQuery->findBySlug($identifier);
    }
}
