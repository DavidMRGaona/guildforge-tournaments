<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;

/**
 * Moves a tournament through its lifecycle by the domain's state machine: each change happens
 * once, with the tournament's row locked, and its events go out after the change commits.
 */
interface TournamentLifecycleServiceInterface
{
    /**
     * Open registration for a draft.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException when it is not a draft
     */
    public function openRegistration(string $tournamentId): void;

    /**
     * Close an open registration.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException when the registration is not open
     */
    public function closeRegistration(string $tournamentId): void;

    /**
     * Start a tournament whose registration is open or closed: TournamentStarted. Only the
     * participants holding a seat count towards its minimum.
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException when it is a draft, or already started or ended
     * @throws InsufficientParticipantsException below its minimum of participants
     */
    public function start(string $tournamentId): void;

    /**
     * Finish a tournament in progress: TournamentFinished, and SurveySubjectEnded for a surveys
     * module (core event).
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException when it is not in progress (also when already finished)
     */
    public function finish(string $tournamentId): void;

    /**
     * Cancel a tournament that has not ended: TournamentCancelled (its participants are mailed),
     * and SurveySubjectCancelled for a surveys module (core event).
     *
     * @throws TournamentNotFoundException
     * @throws InvalidStateTransitionException when it already finished or was cancelled
     */
    public function cancel(string $tournamentId): void;
}
