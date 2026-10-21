<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Support;

use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\RoundId;

/**
 * A round repository that has not seen the tournament's rounds yet.
 *
 * Loaded with require_once: the module autoloader only maps src/, and a helper kept on a
 * test class is missing whenever a parallel run puts its user in another process.
 */
final class StaleRoundRepository implements RoundRepositoryInterface
{
    public function __construct(private readonly RoundRepositoryInterface $inner) {}

    public function save(Round $round): void
    {
        $this->inner->save($round);
    }

    public function find(RoundId $id): ?Round
    {
        return $this->inner->find($id);
    }

    public function findOrFail(RoundId $id): Round
    {
        return $this->inner->findOrFail($id);
    }

    public function findByTournament(string $tournamentId): array
    {
        return [];
    }

    public function findCurrentRound(string $tournamentId): ?Round
    {
        return null;
    }

    public function findByTournamentAndNumber(string $tournamentId, int $roundNumber): ?Round
    {
        return $this->inner->findByTournamentAndNumber($tournamentId, $roundNumber);
    }

    public function findLatestCompletedRound(string $tournamentId): ?Round
    {
        return null;
    }

    public function delete(RoundId $id): void
    {
        $this->inner->delete($id);
    }
}
