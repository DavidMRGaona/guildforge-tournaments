<?php

declare(strict_types=1);

namespace Modules\Tournaments\Domain\Repositories;

use Modules\Tournaments\Domain\Entities\MatchReport;

interface MatchReportRepositoryInterface
{
    public function find(string $matchId): ?MatchReport;

    /**
     * @return array<string, MatchReport> Keyed by match id
     */
    public function findByRound(string $roundId): array;

    /**
     * Store the match's only report, replacing any earlier one.
     */
    public function save(MatchReport $report): void;

    public function delete(string $matchId): void;

    /**
     * Reports of the tournament's matches, pending and disputed alike.
     */
    public function countByTournament(string $tournamentId): int;

    public function deleteByTournament(string $tournamentId): void;
}
