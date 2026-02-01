<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;

interface ParticipantRepositoryInterface
{
    /**
     * Save a participant (create or update).
     */
    public function save(Participant $participant): void;

    /**
     * Find a participant by ID.
     */
    public function find(ParticipantId $id): ?Participant;

    /**
     * Find a participant by ID or throw an exception.
     */
    public function findOrFail(ParticipantId $id): Participant;

    /**
     * Find all participants for a tournament.
     *
     * @return array<Participant>
     */
    public function findByTournament(string $tournamentId): array;

    /**
     * Find participants by tournament and status.
     *
     * @return array<Participant>
     */
    public function findByTournamentAndStatus(string $tournamentId, ParticipantStatus $status): array;

    /**
     * Find a participant by user ID and tournament.
     */
    public function findByUserAndTournament(string $userId, string $tournamentId): ?Participant;

    /**
     * Find a participant by guest email and tournament.
     */
    public function findByGuestEmailAndTournament(string $email, string $tournamentId): ?Participant;

    /**
     * Find a participant by email (either guest_email or user's email) and tournament.
     */
    public function findByEmailAndTournament(string $email, string $tournamentId): ?Participant;

    /**
     * Count participants for a tournament.
     */
    public function countByTournament(string $tournamentId): int;

    /**
     * Count active participants for a tournament.
     */
    public function countActiveByTournament(string $tournamentId): int;

    /**
     * Get participants who can play (confirmed or checked in).
     *
     * @return array<Participant>
     */
    public function findPlayableByTournament(string $tournamentId): array;

    /**
     * Delete a participant.
     */
    public function delete(ParticipantId $id): void;

    /**
     * Find a participant by cancellation token.
     */
    public function findByCancellationToken(string $token): ?Participant;
}
