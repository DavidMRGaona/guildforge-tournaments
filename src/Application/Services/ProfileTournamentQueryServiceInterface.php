<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\ProfileTournamentEntryDTO;

/**
 * The tournaments of a person as another person may see them on the owner's public profile.
 *
 * The rule is the one of the tournament's own page, never a looser one: a draft or cancelled
 * tournament, one whose event is not published and a registration that is not active are left out;
 * the standings of a tournament under way or over are public, and the registrations of one not
 * started yet only when the organisation shows its participants, or to the person themselves.
 * Only the official results count: a player's report waiting for confirmation is never read.
 */
interface ProfileTournamentQueryServiceInterface
{
    /**
     * Tournaments under way first, then the next ones by the date of their event.
     *
     * @return list<ProfileTournamentEntryDTO>
     */
    public function current(string $ownerId, ?string $viewerId, int $limit): array;

    /**
     * The latest finished tournaments, most recent event first.
     *
     * @return list<ProfileTournamentEntryDTO>
     */
    public function recent(string $ownerId, ?string $viewerId, int $limit): array;

    /**
     * How many finished tournaments the person took part in.
     */
    public function playedCount(string $ownerId, ?string $viewerId): int;
}
