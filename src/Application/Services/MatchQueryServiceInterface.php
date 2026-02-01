<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\Services;

use Modules\Tournaments\Application\DTOs\Response\MatchResponseDTO;

interface MatchQueryServiceInterface
{
    /**
     * @return array<MatchResponseDTO>
     */
    public function getMatchesForRound(string $roundId): array;
}
