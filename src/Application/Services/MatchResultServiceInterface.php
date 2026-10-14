<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;

interface MatchResultServiceInterface
{
    /**
     * Record (or clear, with NotPlayed) a match result and recalculate the tournament standings.
     *
     * @throws MatchNotFoundException
     * @throws InvalidMatchResultException When the result does not fit the match (bye or not)
     */
    public function recordResult(RecordMatchResultDTO $dto): void;
}
