<?php

declare(strict_types=1);

namespace Modules\Tournaments\Application\DTOs\Response;

use Modules\Tournaments\Domain\Entities\Standing;

final readonly class StandingsResponseDTO
{
    public function __construct(
        public string $tournamentId,
        public string $participantId,
        public string $participantName,
        public int $rank,
        public int $matchesPlayed,
        public int $wins,
        public int $draws,
        public int $losses,
        public int $byes,
        public float $points,
        public float $buchholz,
        public float $medianBuchholz,
        public float $progressive,
        public float $opponentWinPercentage,
    ) {
    }

    public static function fromEntity(
        Standing $standing,
        string $participantName,
    ): self {
        return new self(
            tournamentId: $standing->tournamentId(),
            participantId: $standing->participantId(),
            participantName: $participantName,
            rank: $standing->rank(),
            matchesPlayed: $standing->matchesPlayed(),
            wins: $standing->wins(),
            draws: $standing->draws(),
            losses: $standing->losses(),
            byes: $standing->byes(),
            points: $standing->points(),
            buchholz: $standing->buchholz(),
            medianBuchholz: $standing->medianBuchholz(),
            progressive: $standing->progressive(),
            opponentWinPercentage: $standing->opponentWinPercentage(),
        );
    }

    public function winPercentage(): float
    {
        if ($this->matchesPlayed === 0) {
            return 0.0;
        }

        return ($this->wins / $this->matchesPlayed) * 100;
    }

    public function drawPercentage(): float
    {
        if ($this->matchesPlayed === 0) {
            return 0.0;
        }

        return ($this->draws / $this->matchesPlayed) * 100;
    }

    public function lossPercentage(): float
    {
        if ($this->matchesPlayed === 0) {
            return 0.0;
        }

        return ($this->losses / $this->matchesPlayed) * 100;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tournament_id' => $this->tournamentId,
            'participant_id' => $this->participantId,
            'participant_name' => $this->participantName,
            'rank' => $this->rank,
            'matches_played' => $this->matchesPlayed,
            'wins' => $this->wins,
            'draws' => $this->draws,
            'losses' => $this->losses,
            'byes' => $this->byes,
            'points' => $this->points,
            'buchholz' => $this->buchholz,
            'median_buchholz' => $this->medianBuchholz,
            'progressive' => $this->progressive,
            'opponent_win_percentage' => $this->opponentWinPercentage,
            'win_percentage' => $this->winPercentage(),
            'draw_percentage' => $this->drawPercentage(),
            'loss_percentage' => $this->lossPercentage(),
        ];
    }
}
