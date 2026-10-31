<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

/**
 * Who organizes a tournament (chosen in the panel): they manage its surveys on the public site.
 */
interface TournamentOrganizersInterface
{
    /**
     * The organizers' user ids, leaving out anonymized accounts.
     *
     * @return list<string>
     */
    public function userIds(string $tournamentId): array;

    public function isOrganizer(string $tournamentId, string $userId): bool;
}
