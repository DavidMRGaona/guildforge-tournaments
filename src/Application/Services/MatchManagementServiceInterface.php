<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\ConfirmMatchResultDTO;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchHistoryResponseDTO;
use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;

interface MatchManagementServiceInterface
{
    /**
     * Report a match result.
     *
     * @throws MatchNotFoundException
     * @throws UnauthorizedToReportResultException When user cannot report results for this match
     */
    public function reportResult(ReportMatchResultDTO $dto): MatchResponseDTO;

    /**
     * Confirm a reported match result (PLAYERS_WITH_CONFIRMATION mode).
     *
     * @throws MatchNotFoundException
     * @throws ResultNotConfirmedException When no result to confirm
     * @throws UnauthorizedToReportResultException When user cannot confirm this match
     */
    public function confirmResult(ConfirmMatchResultDTO $dto): MatchResponseDTO;

    /**
     * Dispute a reported match result.
     *
     * @throws MatchNotFoundException
     * @throws UnauthorizedToReportResultException
     */
    public function disputeResult(string $matchId, string $disputedById, ?string $reason = null): MatchResponseDTO;

    /**
     * Reset a match result (admin action).
     * Creates a history entry for audit trail.
     *
     * @throws MatchNotFoundException
     */
    public function resetResult(string $matchId, string $resetById, ?string $reason = null): MatchResponseDTO;

    /**
     * Get match history (audit trail) for a match.
     *
     * @return array<MatchHistoryResponseDTO>
     */
    public function getHistory(string $matchId): array;

    /**
     * Get a match by ID.
     */
    public function find(string $matchId): ?MatchResponseDTO;

    /**
     * Get all matches for a round.
     *
     * @return array<MatchResponseDTO>
     */
    public function findByRound(string $roundId): array;

    /**
     * Get all matches for a participant in a tournament.
     *
     * @return array<MatchResponseDTO>
     */
    public function findByParticipant(string $participantId, string $tournamentId): array;
}
