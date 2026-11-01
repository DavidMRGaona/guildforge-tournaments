<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use Modules\Tournaments\Domain\Enums\ReportingClosedReason;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\ValueObjects\MatchReportActions;
use Modules\Tournaments\Domain\ValueObjects\StatDefinition;

/**
 * A player's own match in the current round, with what they may do with its result.
 */
final readonly class PlayerMatchResponseDTO
{
    /**
     * @param  1|2  $side  Which player of the match they are
     * @param  array<StatDefinition>  $statDefinitions  What the report asks for each player
     * @param  float|null  $byePoints  What the bye gives them, on a bye
     */
    public function __construct(
        public MatchResponseDTO $match,
        public int $side,
        public ?string $opponentName,
        public bool $opponentIsGuest,
        public MatchReportActions $actions,
        public ?MatchReportResponseDTO $report,
        public ResultReporting $resultReporting,
        public array $statDefinitions,
        public ?ReportingClosedReason $closedReason = null,
        public ?float $byePoints = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'match' => $this->match->toArray(),
            'side' => $this->side,
            'opponent_name' => $this->opponentName,
            'opponent_is_guest' => $this->opponentIsGuest,
            'actions' => $this->actions->toArray(),
            'report' => $this->report?->toArray(),
            'result_reporting' => $this->resultReporting->value,
            'stat_definitions' => array_map(
                static fn (StatDefinition $stat): array => $stat->toArray(),
                $this->statDefinitions,
            ),
            'closed_reason' => $this->closedReason?->value,
            'bye_points' => $this->byePoints,
        ];
    }
}
