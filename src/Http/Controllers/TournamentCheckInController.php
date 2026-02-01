<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Application\Services\UserDataProviderInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\CheckInNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\CheckInWindowClosedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Http\Requests\CheckInRequest;

final class TournamentCheckInController extends Controller
{
    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly ParticipantManagementServiceInterface $participantManagement,
        private readonly UserDataProviderInterface $userDataProvider,
    ) {}

    public function show(string $slug): Response|RedirectResponse
    {
        $tournament = $this->findTournament($slug);

        if ($tournament === null) {
            abort(404);
        }

        if ($tournament->slug !== $slug && ! Str::isUuid($slug)) {
            return redirect()->route('tournaments.check-in.show', $tournament->slug, 301);
        }

        // Get event start date for check-in window calculation
        $eventStartDate = $this->tournamentQuery->getEventStartDate($tournament->id);

        $user = auth()->user();
        $userRegistration = null;
        $checkInWindow = $this->calculateCheckInWindow($tournament, $eventStartDate);

        if ($user !== null) {
            $registration = $this->participantManagement->findByUserAndTournament(
                (string) $user->id,
                $tournament->id
            );
            $userRegistration = $registration?->toArray();
        }

        return Inertia::render('Tournaments/CheckIn', [
            'tournament' => $tournament->toArray(),
            'userRegistration' => $userRegistration,
            'checkInWindow' => $checkInWindow,
            'eventStartDate' => $eventStartDate?->format('c'),
        ]);
    }

    public function store(CheckInRequest $request, string $slug): RedirectResponse
    {
        $tournament = $this->findTournament($slug);

        if ($tournament === null) {
            abort(404);
        }

        // Validate self check-in is allowed
        if (! $tournament->selfCheckInAllowed) {
            return back()->withErrors([
                'check_in' => __('tournaments::messages.check_in.not_allowed'),
            ]);
        }

        // Validate check-in window
        $eventStartDate = $this->tournamentQuery->getEventStartDate($tournament->id);
        $checkInWindow = $this->calculateCheckInWindow($tournament, $eventStartDate);

        if ($checkInWindow['status'] !== 'open') {
            return back()->withErrors([
                'check_in' => __('tournaments::messages.check_in.window_closed'),
            ]);
        }

        $user = auth()->user();

        try {
            if ($user !== null) {
                // Authenticated user - find by user ID
                $participant = $this->participantManagement->checkInByEmail(
                    $tournament->id,
                    $this->userDataProvider->getUserInfo((string) $user->id)['email'] ?? ''
                );
            } else {
                // Guest - find by provided email
                $email = $request->validated('email');
                $participant = $this->participantManagement->checkInByEmail(
                    $tournament->id,
                    $email
                );
            }

            return redirect()
                ->route('tournaments.check-in.show', $tournament->slug)
                ->with('success', __('tournaments::messages.check_in.success'));
        } catch (ParticipantNotFoundException) {
            return back()->withErrors([
                'email' => __('tournaments::messages.check_in.not_found'),
            ]);
        } catch (CheckInNotAllowedException) {
            return back()->withErrors([
                'check_in' => __('tournaments::messages.check_in.not_allowed'),
            ]);
        } catch (CheckInWindowClosedException) {
            return back()->withErrors([
                'check_in' => __('tournaments::messages.check_in.window_closed'),
            ]);
        }
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

    /**
     * Calculate the check-in window status.
     *
     * @return array{status: string, opens_at: string|null, closes_at: string|null}
     */
    private function calculateCheckInWindow(TournamentResponseDTO $tournament, ?DateTimeImmutable $eventStartDate): array
    {
        // If check-in is not required or self check-in not allowed, window is closed
        if (! $tournament->requiresCheckIn || ! $tournament->selfCheckInAllowed) {
            return [
                'status' => 'not_available',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        // If tournament is already in progress or finished, window is closed
        if (in_array($tournament->status, [TournamentStatus::InProgress, TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            return [
                'status' => 'closed',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        if ($eventStartDate === null) {
            return [
                'status' => 'not_available',
                'opens_at' => null,
                'closes_at' => null,
            ];
        }

        $checkInMinutesBefore = $tournament->checkInStartsBefore ?? 30;
        $windowOpensAt = $eventStartDate->modify("-{$checkInMinutesBefore} minutes");
        $windowClosesAt = $eventStartDate;

        $now = new DateTimeImmutable;

        if ($now < $windowOpensAt) {
            return [
                'status' => 'not_yet',
                'opens_at' => $windowOpensAt->format('c'),
                'closes_at' => $windowClosesAt->format('c'),
            ];
        }

        if ($now > $windowClosesAt) {
            return [
                'status' => 'closed',
                'opens_at' => $windowOpensAt->format('c'),
                'closes_at' => $windowClosesAt->format('c'),
            ];
        }

        return [
            'status' => 'open',
            'opens_at' => $windowOpensAt->format('c'),
            'closes_at' => $windowClosesAt->format('c'),
        ];
    }
}
