<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyCheckedInException;
use Modules\Tournaments\Domain\Exceptions\CheckInNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\CheckInWindowClosedException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Http\Requests\CheckInRequest;

final class TournamentCheckInController extends Controller
{
    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly ParticipantManagementServiceInterface $participantManagement,
        private readonly SeoMetadataFactoryInterface $seo,
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
        $eventStartDate = $tournament->eventStartsAt;

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
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('tournaments::messages.seo.check_in_title', ['name' => $tournament->name]),
                noindex: true,
            ))->toArray(),
        ]);
    }

    public function store(CheckInRequest $request, string $slug): JsonResponse|RedirectResponse
    {
        $tournament = $this->findTournament($slug);

        if ($tournament === null) {
            abort(404);
        }

        // Validate self check-in is allowed
        if (! $tournament->selfCheckInAllowed) {
            return $this->refusal($request, 'check_in', 'not_allowed', 403);
        }

        // Validate check-in window
        $eventStartDate = $tournament->eventStartsAt;
        $checkInWindow = $this->calculateCheckInWindow($tournament, $eventStartDate);

        if ($checkInWindow['status'] !== 'open') {
            return $this->refusal($request, 'check_in', 'window_closed', 409);
        }

        $user = auth()->user();

        try {
            if ($user !== null) {
                // A signed-in user checks in their own registration, found by user id
                $this->participantManagement->checkInByUser($tournament->id, (string) $user->id);
            } else {
                // Guest - find by provided email
                $this->participantManagement->checkInByEmail($tournament->id, (string) $request->validated('email'));
            }

            $message = (string) __('tournaments::messages.check_in.success');

            if ($this->wantsJsonResponse($request)) {
                return response()->json(['message' => $message]);
            }

            return redirect()
                ->route('tournaments.check-in.show', $tournament->slug)
                ->with('success', $message);
        } catch (ParticipantNotFoundException) {
            return $user !== null
                ? $this->refusal($request, 'check_in', 'not_registered', 422)
                : $this->refusal($request, 'email', 'not_found', 422);
        } catch (AlreadyCheckedInException) {
            return $this->refusal($request, 'check_in', 'already_done', 409);
        } catch (CheckInNotAllowedException $e) {
            if ($e->emailIsUnconfirmed()) {
                return $this->refusal($request, 'email', 'email_not_confirmed', 422);
            }

            if ($e->registrationIsUnconfirmed()) {
                return $this->refusal($request, 'check_in', 'registration_not_confirmed', 409);
            }

            return $this->refusal($request, 'check_in', 'not_allowed', 403);
        } catch (CheckInWindowClosedException) {
            return $this->refusal($request, 'check_in', 'window_closed', 409);
        } catch (InvalidStateTransitionException) {
            // Fallback: the rules above should have refused the check-in already
            return $this->refusal($request, 'check_in', 'not_allowed', 409);
        }
    }

    /**
     * The check-in page posts with fetch and reads JSON; Inertia visits and plain forms
     * expect a redirect.
     */
    private function wantsJsonResponse(CheckInRequest $request): bool
    {
        if ($request->header('X-Inertia') !== null) {
            return false;
        }

        return $request->expectsJson();
    }

    /**
     * A refused check-in: JSON with the message (also under the field, like a validation
     * error) for fetch, or back to the form with the error.
     *
     * @param  string  $reason  key under tournaments::messages.check_in
     */
    private function refusal(CheckInRequest $request, string $field, string $reason, int $status): JsonResponse|RedirectResponse
    {
        $message = (string) __('tournaments::messages.check_in.'.$reason);

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'message' => $message,
                'errors' => [$field => [$message]],
            ], $status);
        }

        return back()->withErrors([$field => $message]);
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
