<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;

interface ParticipantRepositoryInterface
{
    /**
     * Save a participant (create or update). The guest email is stored trimmed and lowercased.
     *
     * @throws AlreadyRegisteredException when another active guest already uses the email in the tournament
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
     * Find an active (registered, confirmed or checked-in) participant by email and tournament:
     * the active guest registration with that email first, then the account's. Withdrawn, expired
     * and disqualified rows are ignored.
     */
    public function findActiveByEmailAndTournament(string $email, string $tournamentId): ?Participant;

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
     * Participants who played at least one round of the tournament (paired in a match or given
     * a bye), whatever their status now: someone who withdrew or was disqualified halfway counts.
     *
     * @return array<Participant>
     */
    public function findWhoPlayed(string $tournamentId): array;

    /**
     * Delete a participant.
     */
    public function delete(ParticipantId $id): void;

    /**
     * Find a participant by cancellation token.
     */
    public function findByCancellationToken(string $token): ?Participant;

    /**
     * Find a participant by the token of its email confirmation link.
     */
    public function findByConfirmationToken(string $token): ?Participant;

    /**
     * Count active guest registrations holding a seat while their email is still unconfirmed.
     */
    public function countUnconfirmedHeldByTournament(string $tournamentId): int;

    /**
     * IDs of active guest registrations whose email is not confirmed yet.
     *
     * @return list<string>
     */
    public function findIdsAwaitingConfirmation(): array;

    /**
     * IDs of the tournaments that still store at least one fingerprint.
     *
     * @return list<string>
     */
    public function findTournamentIdsWithFingerprints(): array;

    /**
     * Erase the fingerprints of a tournament's registrations.
     *
     * @return int number of rows cleared
     */
    public function clearFingerprints(string $tournamentId): int;

    /**
     * IDs of active registrations that share a fingerprint with another active registration of the tournament,
     * where at least one of the pair is a guest and both were made within the window.
     *
     * @return list<string>
     */
    public function findPossibleDuplicateIds(string $tournamentId, int $windowHours): array;
}
