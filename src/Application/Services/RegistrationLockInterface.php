<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Closure;

/**
 * Serialises the registrations and confirmations of one tournament: the callback runs
 * in a transaction that holds the lock of the tournament, so two requests never take the same
 * seat or confirm the same registration twice.
 */
interface RegistrationLockInterface
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function forTournament(string $tournamentId, Closure $callback): mixed;

    /**
     * The lock of the participant's tournament.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function forParticipant(string $participantId, Closure $callback): mixed;

    /**
     * The lock of the tournament of the participant holding the guest confirmation token.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function forConfirmationToken(string $token, Closure $callback): mixed;
}
