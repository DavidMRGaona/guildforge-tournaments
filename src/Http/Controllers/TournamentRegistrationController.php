<?php

declare(strict_types=1);

namespace Modules\Tournaments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Application\Services\ParticipantManagementServiceInterface;
use Modules\Tournaments\Application\Services\TournamentQueryServiceInterface;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\GuestRegistrationNotAllowedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;
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

        if ($tournament === null) {
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

        // Handle unauthenticated users
        if ($user === null) {
            $tournament = $this->tournamentQuery->find($tournamentId);

            if ($tournament === null) {
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

            // Register as guest
            try {
                $registration = $this->participantService->register(new RegisterParticipantDTO(
                    tournamentId: $tournamentId,
                    userId: null,
                    guestName: $request->validated('guest_name'),
                    guestEmail: $request->validated('guest_email'),
                ));

                if ($this->wantsJsonResponse($request)) {
                    return response()->json([
                        'message' => __('tournaments::messages.messages.registered_successfully'),
                        'data' => $registration->toArray(),
                    ], 201);
                }

                return back()->with('success', __('tournaments::messages.messages.registered_successfully'));
            } catch (TournamentNotFoundException) {
                return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_found'), 404);
            } catch (TournamentNotOpenException) {
                return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_open'), 400);
            } catch (TournamentFullException) {
                return $this->errorResponse($request, __('tournaments::messages.errors.tournament_full'), 400);
            } catch (AlreadyRegisteredException) {
                return $this->errorResponse($request, __('tournaments::messages.errors.already_registered'), 400);
            } catch (GuestRegistrationNotAllowedException) {
                return $this->errorResponse($request, __('tournaments::messages.errors.guests_not_allowed'), 403);
            }
        }

        // Register authenticated user
        try {
            $registration = $this->participantService->register(new RegisterParticipantDTO(
                tournamentId: $tournamentId,
                userId: (string) $user->id,
            ));

            if ($this->wantsJsonResponse($request)) {
                return response()->json([
                    'message' => __('tournaments::messages.messages.registered_successfully'),
                    'data' => $registration->toArray(),
                ], 201);
            }

            return back()->with('success', __('tournaments::messages.messages.registered_successfully'));
        } catch (TournamentNotFoundException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_found'), 404);
        } catch (TournamentNotOpenException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_not_open'), 400);
        } catch (TournamentFullException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.tournament_full'), 400);
        } catch (AlreadyRegisteredException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.already_registered'), 400);
        } catch (UserNotAllowedToRegisterException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.user_not_allowed'), 403);
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

        if ($registration === null) {
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
        } catch (CannotWithdrawException) {
            return $this->errorResponse($request, __('tournaments::messages.errors.cannot_withdraw'), 400);
        }
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
