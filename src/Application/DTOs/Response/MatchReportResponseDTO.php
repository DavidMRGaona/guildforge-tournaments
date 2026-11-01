<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;

/**
 * A player's report waiting for confirmation, as its players and the organization see it.
 * Anyone else only learns whether it is pending or disputed.
 */
final readonly class MatchReportResponseDTO
{
    /**
     * @param  array<string, mixed>|null  $player1Stats
     * @param  array<string, mixed>|null  $player2Stats
     */
    public function __construct(
        public MatchResult $result,
        public ?int $player1Score,
        public ?int $player2Score,
        public ?array $player1Stats,
        public ?array $player2Stats,
        public string $reportedById,
        public string $reportedByName,
        public DateTimeImmutable $reportedAt,
        public ?string $disputeReason = null,
        public ?DateTimeImmutable $disputedAt = null,
        public string $fingerprint = '',
    ) {}

    public static function fromEntity(MatchReport $report, string $reportedByName): self
    {
        return new self(
            result: $report->result(),
            player1Score: $report->player1Score(),
            player2Score: $report->player2Score(),
            player1Stats: $report->player1Stats(),
            player2Stats: $report->player2Stats(),
            reportedById: $report->reportedById(),
            reportedByName: $reportedByName,
            reportedAt: $report->reportedAt(),
            disputeReason: $report->disputeReason(),
            disputedAt: $report->disputedAt(),
            fingerprint: $report->fingerprint(),
        );
    }

    public function isDisputed(): bool
    {
        return $this->disputedAt instanceof DateTimeImmutable;
    }

    /**
     * @return array{status: 'pending'|'disputed'}
     */
    public function toPublicArray(): array
    {
        return ['status' => $this->isDisputed() ? 'disputed' : 'pending'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $timezone = app(TimezoneServiceInterface::class);

        return [
            ...$this->toPublicArray(),
            'result' => $this->result->value,
            'result_label' => $this->result->label(),
            'player_1_score' => $this->player1Score,
            'player_2_score' => $this->player2Score,
            'player_1_stats' => $this->player1Stats,
            'player_2_stats' => $this->player2Stats,
            'reported_by_id' => $this->reportedById,
            'reported_by_name' => $this->reportedByName,
            'reported_at' => $timezone->iso($this->reportedAt),
            'dispute_reason' => $this->disputeReason,
            'disputed_at' => $timezone->iso($this->disputedAt),
            'fingerprint' => $this->fingerprint,
        ];
    }
}
