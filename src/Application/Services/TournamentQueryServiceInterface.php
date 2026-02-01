<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use DateTimeImmutable;
use Modules\Tournaments\Application\DTOs\Response\ParticipantResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\StandingsResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\TournamentResponseDTO;

interface TournamentQueryServiceInterface
{
    /**
     * Get a tournament by ID.
     */
    public function find(string $tournamentId): ?TournamentResponseDTO;

    /**
     * Get a tournament by slug.
     */
    public function findBySlug(string $slug): ?TournamentResponseDTO;

    /**
     * Get a tournament by event ID.
     */
    public function findByEventId(string $eventId): ?TournamentResponseDTO;

    /**
     * Get current standings for a tournament.
     *
     * @return array<StandingsResponseDTO>
     */
    public function getStandings(string $tournamentId): array;

    /**
     * Get all participants for a tournament.
     *
     * @return array<ParticipantResponseDTO>
     */
    public function getParticipants(string $tournamentId): array;

    /**
     * Check if a user can register for a tournament.
     * Validates: registration open, capacity, role permissions.
     *
     * @param  array<string>  $userRoles  The user's roles
     */
    public function canUserRegister(string $tournamentId, ?string $userId, array $userRoles = []): bool;

    /**
     * Check if a user can report a result for a match.
     * Validates based on tournament's resultReporting setting.
     */
    public function canUserReportResult(string $matchId, string $userId, bool $isAdmin = false): bool;

    /**
     * Check if a user can confirm a result for a match.
     */
    public function canUserConfirmResult(string $matchId, string $userId): bool;

    /**
     * Get participant count for a tournament.
     */
    public function getParticipantCount(string $tournamentId): int;

    /**
     * Count confirmed/checked-in participants for a tournament.
     */
    public function getActiveParticipantCount(string $tournamentId): int;

    /**
     * Get published tournaments with pagination.
     * Excludes draft and cancelled tournaments.
     * Ordered by priority: in_progress → registration_open/closed → finished.
     *
     * @param  array<string>|null  $statusFilter  Optional filter by status values
     * @return array<TournamentResponseDTO>
     */
    public function getPublishedPaginated(int $page = 1, int $perPage = 12, ?array $statusFilter = null): array;

    /**
     * Get total count of published tournaments.
     * Excludes draft and cancelled tournaments.
     *
     * @param  array<string>|null  $statusFilter  Optional filter by status values
     */
    public function getPublishedTotal(?array $statusFilter = null): int;

    /**
     * Get the event start date for a tournament.
     * Used for check-in window calculation.
     */
    public function getEventStartDate(string $tournamentId): ?DateTimeImmutable;
}
