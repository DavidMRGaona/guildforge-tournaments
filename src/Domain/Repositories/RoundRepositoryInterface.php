<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\ValueObjects\RoundId;

interface RoundRepositoryInterface
{
    /**
     * Save a round (create or update).
     */
    public function save(Round $round): void;

    /**
     * Find a round by ID.
     */
    public function find(RoundId $id): ?Round;

    /**
     * Find a round by ID or throw an exception.
     */
    public function findOrFail(RoundId $id): Round;

    /**
     * Find all rounds for a tournament.
     *
     * @return array<Round>
     */
    public function findByTournament(string $tournamentId): array;

    /**
     * Find the current round for a tournament (latest in progress or pending).
     */
    public function findCurrentRound(string $tournamentId): ?Round;

    /**
     * Find a specific round by tournament and round number.
     */
    public function findByTournamentAndNumber(string $tournamentId, int $roundNumber): ?Round;

    /**
     * Get the latest completed round.
     */
    public function findLatestCompletedRound(string $tournamentId): ?Round;

    /**
     * Delete a round.
     */
    public function delete(RoundId $id): void;
}
