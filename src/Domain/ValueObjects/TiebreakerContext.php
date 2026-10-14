<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

/**
 * What a tournament adds to its matches for the tiebreakers: the points each player
 * earned per match under its scoring rules, the round numbers and its random seed.
 */
final readonly class TiebreakerContext
{
    /**
     * @param  array<string, array<string, float>>  $matchPoints  match id => participant id => points
     * @param  array<string, int>  $roundNumbers  round id => round number
     */
    public function __construct(
        public array $matchPoints = [],
        public array $roundNumbers = [],
        public string $randomSeed = '',
    ) {
    }

    public function pointsFor(string $matchId, string $participantId): ?float
    {
        return $this->matchPoints[$matchId][$participantId] ?? null;
    }

    public function roundNumber(string $roundId): ?int
    {
        return $this->roundNumbers[$roundId] ?? null;
    }
}
