<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;

interface TournamentRepositoryInterface
{
    /**
     * Save a tournament (create or update).
     */
    public function save(Tournament $tournament): void;

    /**
     * Find a tournament by ID.
     */
    public function find(TournamentId $id): ?Tournament;

    /**
     * Find a tournament by ID or throw an exception.
     */
    public function findOrFail(TournamentId $id): Tournament;

    /**
     * Find a tournament by slug.
     */
    public function findBySlug(string $slug): ?Tournament;

    /**
     * Find a tournament by event ID.
     */
    public function findByEventId(string $eventId): ?Tournament;

    /**
     * Find tournaments by status.
     *
     * @return array<Tournament>
     */
    public function findByStatus(TournamentStatus $status): array;

    /**
     * Delete a tournament.
     */
    public function delete(TournamentId $id): void;

    /**
     * Get all tournaments.
     *
     * @return array<Tournament>
     */
    public function all(): array;
}
