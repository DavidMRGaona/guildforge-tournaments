<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\Standing;

interface StandingRepositoryInterface
{
    /**
     * Save a standing (create or update).
     */
    public function save(Standing $standing): void;

    /**
     * Save multiple standings at once.
     *
     * @param  array<Standing>  $standings
     */
    public function saveMany(array $standings): void;

    /**
     * Find all standings for a tournament.
     *
     * @return array<Standing>
     */
    public function findByTournament(string $tournamentId): array;

    /**
     * Find all standings for a tournament ordered by rank.
     *
     * @return array<Standing>
     */
    public function findByTournamentOrderedByRank(string $tournamentId): array;

    /**
     * Find a standing for a specific participant.
     */
    public function findByParticipant(string $participantId): ?Standing;

    /**
     * Find a standing for a specific participant in a tournament.
     */
    public function findByParticipantAndTournament(string $participantId, string $tournamentId): ?Standing;

    /**
     * Delete all standings for a tournament.
     */
    public function deleteByTournament(string $tournamentId): void;
}
