<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\MatchReportOutcome;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;

/**
 * Results the players report from the public site, and the organization's confirmation of them.
 */
interface MatchReportingServiceInterface
{
    /**
     * Report the result, or correct one's own report while it waits for confirmation.
     *
     * @throws MatchNotFoundException
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     * @throws InvalidMatchResultException
     */
    public function report(ReportMatchResultDTO $dto): MatchReportOutcome;

    /**
     * The opponent accepts the reported result, which becomes official. $seenReport is the
     * fingerprint of the report they were shown: a report corrected meanwhile is refused.
     *
     * @throws MatchNotFoundException
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     * @throws ResultNotConfirmedException
     */
    public function confirm(string $matchId, string $userId, string $seenReport): void;

    /**
     * The opponent rejects the reported result they were shown: it stays waiting for the
     * organization.
     *
     * @throws MatchNotFoundException
     * @throws UnauthorizedToReportResultException
     * @throws MatchReportClosedException
     * @throws ResultNotConfirmedException
     */
    public function dispute(string $matchId, string $userId, string $reason, string $seenReport): void;

    /**
     * The organization accepts a report, disputed or not.
     *
     * @throws MatchNotFoundException
     * @throws ResultNotConfirmedException
     */
    public function confirmAsOrganizer(string $matchId, string $organizerId): void;

    /**
     * The organization accepts every report of the round that nobody disputed.
     *
     * @return int How many results became official
     */
    public function confirmPendingInRound(string $roundId, string $organizerId): int;
}
