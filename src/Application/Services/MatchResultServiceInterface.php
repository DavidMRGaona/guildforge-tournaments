<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Exceptions\MatchResultLockedException;

interface MatchResultServiceInterface
{
    /**
     * Record (or clear, with NotPlayed) a match result and recalculate the tournament standings.
     *
     * @throws MatchNotFoundException
     * @throws InvalidMatchResultException When the result does not fit the match (bye or not)
     * @throws MatchResultLockedException When clearing a result of a finished round followed by another
     */
    public function recordResult(RecordMatchResultDTO $dto): void;

    /**
     * Record several results at once: all or none are stored, and the standings are
     * recalculated once per tournament.
     *
     * @param  array<RecordMatchResultDTO>  $dtos
     *
     * @throws MatchNotFoundException
     * @throws InvalidMatchResultException
     * @throws MatchResultLockedException
     */
    public function recordResults(array $dtos): void;
}
