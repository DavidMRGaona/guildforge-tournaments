<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\DTOs\Response\GuestRegistrationOutcome;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\GuestEmailBelongsToAccountException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;
use Modules\Tournaments\Http\RegistrationFingerprint;
use Modules\Tournaments\Http\Requests\RegisterParticipantRequest;

final class TournamentRegistrationController extends Controller
{
    public function __construct(
        private readonly TournamentQueryServiceInterface $tournamentQuery,
        private readonly ParticipantManagementServiceInterface $participantService,
    ) {}

    /**
     * Check if the request expects a JSON response.
     * Inertia requests should receive redirects, not JSON.
     */
    private function wantsJsonResponse(RegisterParticipantRequest $request): bool
    {
        // Inertia requests send X-Inertia header but expect redirects, not JSON
        if ($request->header('X-Inertia') !== null) {
            return false;
        }

        return $request->expectsJson();
    }

    /**
     * Get current user's registration status for a tournament.
     */
    public function show(string $tournamentId): JsonResponse
    {
        $tournament = $this->tournamentQuery->find($tournamentId);

        if (!$tournament instanceof \Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO) {
            return response()->json([
                'message' => __('tournaments::messages.errors.tournament_not_found'),
            ], 404);
        }

        $user = auth()->user();

        if ($user === null) {
            return response()->json([
                'data' => [
                    'registration' => null,
                    'can_register' => $tournament->allowGuests && $tournament->isRegistrationOpen() && $tournament->hasCapacity(),
                ],
            ]);
        }

        $registration = $this->participantService->findByUserAndTournament(
            (string) $user->id,
            $tournamentId
        );

        $canRegister = $this->tournamentQuery->canUserRegister(
            $tournamentId,
            (string) $user->id,
            $user->roles()->pluck('name')->toArray()
        );

        return response()->json([
            'data' => [
                'registration' => $registration?->toArray(),
                'can_register' => $canRegister,
            ],
        ]);
    }

    /**
     * Register the current user or guest for a tournament.
     */
    public function store(RegisterParticipantRequest $request, string $tournamentId): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->storeGuest($request, $tournamentId);
        }

        try {
            $registration = $this->participantService->register(new RegisterParticipantDTO(
                tournamentId: $tournamentId,
                userId: (string) $user->id,
                fingerprint: RegistrationFingerprint::forRequest($request),
            ));

            $message = $registration->claimedGuestRegistration
                ? __('tournaments::messages.messages.guest_registration_claimed')
                : __('tournaments::messages.messages.registered_successfully');

            return $this->successResponse($request, $message, $registration->toArray(), 201);
        } catch (TournamentNotFoundException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_found'), 404);
        } catch (TournamentNotOpenException $e) {
            return $this->errorResponse($request, $this->notOpenMessage($e), 400);
        } catch (TournamentFullException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_full'), 400);
        } catch (AlreadyRegisteredException $e) {
            // A guest registration with the account email that the account cannot take over
            return $this->errorResponse($request, $e->isAccountEmailTaken()
                ? __('tournaments::messages.errors.registration_with_your_email')
                : __('tournaments::messages.errors.already_registered'), 400);
        } catch (UserNotAllowedToRegisterException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.user_not_allowed'), 403);
        } catch (GuestRegistrationNotAllowedException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.guests_not_allowed'), 403);
        }
    }

    /**
     * A guest submission waits for the email confirmation: the answer says so, or that one is
     * already pending, and nothing about the organisation hearing of it yet.
     */
    private function storeGuest(RegisterParticipantRequest $request, string $tournamentId): JsonResponse|RedirectResponse
    {
        $tournament = $this->tournamentQuery->find($tournamentId);

        if (!$tournament instanceof \Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_found'), 404);
        }

        // If guests are not allowed, redirect to login
        if (! $tournament->allowGuests) {
            if ($this->wantsJsonResponse($request)) {
                return response()->json([
                    'message' => __('tournaments::messages.errors.unauthenticated'),
                ], 401);
            }

            return redirect()->route('login');
        }

        try {
            $result = $this->participantService->registerGuest(new RegisterParticipantDTO(
                tournamentId: $tournamentId,
                guestName: $request->validated('guest_name'),
                guestEmail: $request->validated('guest_email'),
                fingerprint: RegistrationFingerprint::forRequest($request),
            ));

            if ($result->outcome !== GuestRegistrationOutcome::ConfirmationSent) {
                // Resent or within the resend margin: the same answer, so the margin is not probed
                return $this->successResponse(
                    $request,
                    __('tournaments::messages.messages.guest_confirmation_pending'),
                    $result->toArray(),
                    200,
                );
            }

            $message = $result->seatHeld && $result->expiresAt instanceof \DateTimeImmutable
                ? __('tournaments::messages.messages.guest_confirmation_sent_hold', [
                    'date' => $result->expiresAt->format('d/m/Y H:i'),
                ])
                : __('tournaments::messages.messages.guest_confirmation_sent_wait');

            return $this->successResponse($request, $message, $result->toArray(), 201);
        } catch (TournamentNotFoundException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_found'), 404);
        } catch (TournamentNotOpenException $e) {
            return $this->errorResponse($request, $this->notOpenMessage($e), 400);
        } catch (TournamentFullException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_full'), 400);
        } catch (GuestEmailBelongsToAccountException) {
            $message = __('tournaments::messages.errors.guest_email_has_account');

            if ($this->wantsJsonResponse($request)) {
                return response()->json([
                    'message' => $message,
                    'login_url' => route('login'),
                ], 409);
            }

            return back()->with('error', $message);
        } catch (AlreadyRegisteredException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.registration_with_this_email'), 400);
        } catch (GuestRegistrationNotAllowedException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.guests_not_allowed'), 403);
        }
    }

    /**
     * Cancel the current user's registration for a tournament.
     */
    public function destroy(RegisterParticipantRequest $request, string $tournamentId): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            if ($this->wantsJsonResponse($request)) {
                return response()->json([
                    'message' => __('tournaments::messages.errors.unauthenticated'),
                ], 401);
            }

            return redirect()->route('login');
        }

        $registration = $this->participantService->findByUserAndTournament(
            (string) $user->id,
            $tournamentId
        );

        if (!$registration instanceof \Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO) {
            return $this->errorResponse($request, __('tournaments::messages.errors.not_registered'), 404);
        }

        try {
            $this->participantService->withdraw($registration->id);

            if ($this->wantsJsonResponse($request)) {
                return response()->json([
                    'message' => __('tournaments::messages.messages.withdrawn_successfully'),
                ]);
            }

            return back()->with('success', __('tournaments::messages.messages.withdrawn_successfully'));
        } catch (ParticipantNotFoundException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.not_registered'), 404);
        } catch (CannotWithdrawException $e) {
            return $this->errorResponse($request, $e->eventHasStarted()
                ? __('tournaments::messages.errors.cannot_withdraw_started')
                : __('tournaments::messages.errors.cannot_withdraw'), 400);
        }
    }

    private function notOpenMessage(TournamentNotOpenException $e): string
    {
        return $e->eventHasStarted()
            ? __('tournaments::messages.errors.tournament_started')
            : __('tournaments::messages.errors.tournament_not_open');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successResponse(
        RegisterParticipantRequest $request,
        string $message,
        array $data,
        int $status
    ): JsonResponse|RedirectResponse {
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'message' => $message,
                'data' => $data,
            ], $status);
        }

        return back()->with('success', $message);
    }

    private function errorResponse(
        RegisterParticipantRequest $request,
        string $message,
        int $status
    ): JsonResponse|RedirectResponse {
        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'message' => $message,
            ], $status);
        }

        return back()->with('error', $message);
    }
}
