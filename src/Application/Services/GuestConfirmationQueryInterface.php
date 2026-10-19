<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

/**
 * What the guest form says before submitting: whether a new registration would hold a seat
 * until a deadline or wait without one, computed with the same rules as the submission.
 */
interface GuestConfirmationQueryInterface
{
    /**
     * Null when the tournament does not exist or does not admit guests.
     *
     * @return array{mode: 'hold'|'wait', expires_at: string|null}|null
     */
    public function forTournament(string $tournamentId): ?array;
}
