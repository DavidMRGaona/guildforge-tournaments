<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Services;

use Modules\Tournaments\Domain\ValueObjects\FinalStanding;

/**
 * The placement of each participant in a finished tournament, from its final standings.
 *
 * The standings store a strict order (rank 1, 2, 3…) even when two rows are level on everything the
 * tournament uses to tell them apart. A placement is what the table really says: rows with the
 * same points and the same tiebreaker values share it (1, 1, 3), and the next row skips as many
 * places as there were rows in the tie. 1 is the winner.
 *
 * The caller passes every row of the final standings, whoever it belongs to and whatever their
 * status now (a player who withdrew or was disqualified still holds their place, so nobody moves
 * up); the rule places exactly the rows it receives, sorted by their stored rank.
 */
final class FinalPlacementRule
{
    /** Decimals kept when comparing, as the standings store them */
    private const int PRECISION = 4;

    /**
     * @param  list<FinalStanding>  $standings
     * @return array<string, int> Placement by participant id, best first
     */
    public function placements(array $standings): array
    {
        usort($standings, static fn (FinalStanding $a, FinalStanding $b): int => [$a->rank, $a->participantId] <=> [$b->rank, $b->participantId]);

        $placements = [];
        $previous = null;
        $placement = 0;

        foreach ($standings as $index => $standing) {
            if (! $previous instanceof FinalStanding || ! $this->tied($previous, $standing)) {
                $placement = $index + 1;
            }

            $placements[$standing->participantId] = $placement;
            $previous = $standing;
        }

        return $placements;
    }

    private function tied(FinalStanding $a, FinalStanding $b): bool
    {
        return $this->signature($a) === $this->signature($b);
    }

    /**
     * @return array{float, array<string, float>}
     */
    private function signature(FinalStanding $standing): array
    {
        $tiebreakers = array_map(static fn (float $value): float => round($value, self::PRECISION), $standing->tiebreakers);
        ksort($tiebreakers);

        return [round($standing->points, self::PRECISION), $tiebreakers];
    }
}
