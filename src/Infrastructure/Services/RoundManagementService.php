<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\DTOs\Response\RoundResponseDTO;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\CannotGeneratePairingsException;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\PreviousRoundNotCompletedException;
use Modules\Tournaments\Domain\Exceptions\RoundAlreadyGeneratedException;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\Clock;
use Modules\Tournaments\Domain\Services\SwissPairingServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\PairingConfig;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class RoundManagementService implements RoundManagementServiceInterface
{
    private const int MIN_PLAYERS_PER_ROUND = 2;

    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private ParticipantRepositoryInterface $participants,
        private RoundRepositoryInterface $rounds,
        private MatchRepositoryInterface $matches,
        private SwissPairingServiceInterface $pairing,
        private StandingsServiceInterface $standings,
        private GameProfileRepositoryInterface $gameProfiles,
        private Clock $clock,
    ) {
    }

    public function generateNextRound(string $tournamentId): RoundResponseDTO
    {
        try {
            return $this->generateInTransaction($tournamentId);
        } catch (UniqueConstraintViolationException) {
            // Another request saved the same round number first (unique tournament + number)
            throw RoundAlreadyGeneratedException::meanwhile($tournamentId);
        }
    }

    private function generateInTransaction(string $tournamentId): RoundResponseDTO
    {
        return DB::transaction(function () use ($tournamentId): RoundResponseDTO {
            $tournament = $this->tournaments->findOrFail(TournamentId::fromString($tournamentId));

            if ($tournament->status() !== TournamentStatus::InProgress) {
                throw CannotGeneratePairingsException::tournamentNotInProgress($tournamentId);
            }

            $rounds = $this->rounds->findByTournament($tournamentId);
            $lastRound = $this->lastRound($rounds);

            if ($lastRound !== null
                && (! $lastRound->isFinished() || $this->matches->countUnreportedByRound($lastRound->id()->value) > 0)) {
                throw PreviousRoundNotCompletedException::roundNotFinished($tournamentId, $lastRound->roundNumber());
            }

            $roundNumber = ($lastRound?->roundNumber() ?? 0) + 1;
            $maxRounds = $tournament->maxRounds();
            if ($maxRounds !== null && $roundNumber > $maxRounds) {
                throw CannotGeneratePairingsException::maxRoundsReached($tournamentId, $maxRounds);
            }

            $players = $this->participants->findPlayableByTournament($tournamentId);
            if (count($players) < self::MIN_PLAYERS_PER_ROUND) {
                throw InsufficientParticipantsException::belowMinimum($tournamentId, count($players), self::MIN_PLAYERS_PER_ROUND);
            }

            $pairings = $this->pairing->generatePairings(
                $players,
                $this->standings->recalculate($tournamentId),
                $this->previousMatchups($rounds),
                $roundNumber,
                $this->pairingConfig($tournament),
            );

            $round = new Round(RoundId::generate(), $tournamentId, $roundNumber, RoundStatus::Pending);
            $this->rounds->save($round);

            $matches = array_map(
                static fn (TournamentMatch $pairing): TournamentMatch => new TournamentMatch(
                    id: $pairing->id(),
                    roundId: $round->id()->value,
                    player1Id: $pairing->player1Id(),
                    player2Id: $pairing->player2Id(),
                    result: $pairing->result(),
                    tableNumber: $pairing->tableNumber(),
                ),
                $pairings,
            );
            $this->matches->saveMany($matches);
            $this->markByes($players, $matches);

            TournamentModel::query()->whereKey($tournamentId)->update(['current_round' => $roundNumber]);

            // A bye scores at once
            $this->standings->recalculate($tournamentId);

            return RoundResponseDTO::fromEntity(
                $round,
                count($matches),
                count(array_filter($matches, static fn (TournamentMatch $match): bool => $match->isCompleted())),
            );
        });
    }

    public function startRound(string $roundId): RoundResponseDTO
    {
        $round = $this->rounds->findOrFail(RoundId::fromString($roundId));
        $round->start($this->clock->now());
        $this->rounds->save($round);

        return $this->toResponse($round);
    }

    public function completeRound(string $roundId): RoundResponseDTO
    {
        $round = $this->rounds->findOrFail(RoundId::fromString($roundId));

        $unreported = $this->matches->countUnreportedByRound($roundId);
        if ($unreported > 0) {
            throw PreviousRoundNotCompletedException::hasUnreportedMatches($roundId, $unreported);
        }

        $round->complete($this->clock->now());
        $this->rounds->save($round);

        return $this->toResponse($round);
    }

    public function find(string $roundId): ?RoundResponseDTO
    {
        $round = $this->rounds->find(RoundId::fromString($roundId));

        return $round !== null ? $this->toResponse($round) : null;
    }

    /**
     * @return array<RoundResponseDTO>
     */
    public function findByTournament(string $tournamentId): array
    {
        return array_map(
            fn (Round $round): RoundResponseDTO => $this->toResponse($round),
            $this->rounds->findByTournament($tournamentId),
        );
    }

    public function getCurrentRound(string $tournamentId): ?RoundResponseDTO
    {
        $round = $this->rounds->findCurrentRound($tournamentId);

        return $round !== null ? $this->toResponse($round) : null;
    }

    /**
     * The tournament's pairing options, else its game profile's, else the defaults.
     */
    private function pairingConfig(Tournament $tournament): PairingConfig
    {
        if ($tournament->pairingConfig() !== null) {
            return $tournament->pairingConfig();
        }

        $profileId = $tournament->gameProfileId();
        $profile = $profileId !== null ? $this->gameProfiles->find(GameProfileId::fromString($profileId)) : null;

        return $profile?->pairingConfig() ?? new PairingConfig();
    }

    /**
     * @param  array<Round>  $rounds
     */
    private function lastRound(array $rounds): ?Round
    {
        $last = null;
        foreach ($rounds as $round) {
            if ($last === null || $round->roundNumber() > $last->roundNumber()) {
                $last = $round;
            }
        }

        return $last;
    }

    /**
     * @param  array<Round>  $rounds
     * @return array<array{player1Id: string, player2Id: ?string}>
     */
    private function previousMatchups(array $rounds): array
    {
        $matchups = [];
        foreach ($rounds as $round) {
            foreach ($this->matches->findByRound($round->id()->value) as $match) {
                $matchups[] = ['player1Id' => $match->player1Id(), 'player2Id' => $match->player2Id()];
            }
        }

        return $matchups;
    }

    /**
     * @param  array<Participant>  $players
     * @param  array<TournamentMatch>  $matches
     */
    private function markByes(array $players, array $matches): void
    {
        foreach ($matches as $match) {
            if (! $match->isBye()) {
                continue;
            }

            foreach ($players as $player) {
                if ($player->id()->value === $match->player1Id()) {
                    $player->markByeReceived();
                    $this->participants->save($player);
                }
            }
        }
    }

    private function toResponse(Round $round): RoundResponseDTO
    {
        $matches = $this->matches->findByRound($round->id()->value);

        return RoundResponseDTO::fromEntity(
            $round,
            count($matches),
            count(array_filter($matches, static fn (TournamentMatch $match): bool => $match->isCompleted())),
        );
    }
}
