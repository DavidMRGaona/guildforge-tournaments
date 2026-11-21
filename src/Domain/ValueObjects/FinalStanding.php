<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\ValueObjects;

/**
 * One row of a tournament's final standings, as far as placing it goes: where it was stored,
 * and what it scored. Two rows with the same points and the same tiebreaker values are tied.
 */
final readonly class FinalStanding
{
    /**
     * @param  array<string, float>  $tiebreakers  Every tiebreaker value the row carries, by key
     */
    public function __construct(
        public string $participantId,
        public int $rank,
        public float $points,
        public array $tiebreakers,
    ) {}
}
