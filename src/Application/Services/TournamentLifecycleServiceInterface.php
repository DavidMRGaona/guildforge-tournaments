<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotFoundException;

/**
 * Ends a tournament, one way or the other, through the domain: the change and its events
 * happen once, and the events go out after the change commits.
 */
interface TournamentLifecycleServiceInterface
{
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
