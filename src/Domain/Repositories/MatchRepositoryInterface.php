<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\ValueObjects\MatchId;

interface MatchRepositoryInterface
{
    /**
     * Save a match (create or update).
     */
    public function save(TournamentMatch $match): void;

    /**
     * Save multiple matches at once.
     *
     * @param  array<TournamentMatch>  $matches
     */
    public function saveMany(array $matches): void;

    /**
     * Find a match by ID.
     */
    public function find(MatchId $id): ?TournamentMatch;

    /**
     * Find a match by ID or throw an exception.
     */
    public function findOrFail(MatchId $id): TournamentMatch;

    /**
     * Find all matches for a round.
     *
     * @return array<TournamentMatch>
     */
    public function findByRound(string $roundId): array;

    /**
     * Find all matches involving a participant.
     *
     * @return array<TournamentMatch>
     */
    public function findByParticipant(string $participantId): array;

    /**
     * Find all matches involving a participant in a tournament.
     *
     * @return array<TournamentMatch>
     */
    public function findByParticipantAndTournament(string $participantId, string $tournamentId): array;

    /**
     * Check if two participants have already played against each other in the tournament.
     */
    public function havePlayedBefore(string $participant1Id, string $participant2Id, string $tournamentId): bool;

    /**
     * Count unreported matches for a round.
     */
    public function countUnreportedByRound(string $roundId): int;

    /**
     * Find the match for a specific participant in a specific round.
     */
    public function findByParticipantAndRound(string $participantId, string $roundId): ?TournamentMatch;

    /**
     * Delete a match.
     */
    public function delete(MatchId $id): void;
}
