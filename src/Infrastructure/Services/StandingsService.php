<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\Services\StandingsServiceInterface;
use Modules\Tournaments\Domain\Entities\Participant;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Entities\Tournament;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\TiebreakerType;
use Modules\Tournaments\Domain\Repositories\GameProfileRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\ParticipantRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\Services\StandingCalculatorServiceInterface;
use Modules\Tournaments\Domain\ValueObjects\GameProfileId;
use Modules\Tournaments\Domain\ValueObjects\ScoringRule;
use Modules\Tournaments\Domain\ValueObjects\TiebreakerDefinition;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;

final readonly class StandingsService implements StandingsServiceInterface
{
    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private GameProfileRepositoryInterface $gameProfiles,
        private ParticipantRepositoryInterface $participants,
        private RoundRepositoryInterface $rounds,
        private MatchRepositoryInterface $matches,
        private StandingRepositoryInterface $standings,
        private StandingCalculatorServiceInterface $calculator,
    ) {}

    /**
     * @return array<Standing>
     */
    public function recalculate(string $tournamentId): array
    {
        $tournament = $this->tournaments->findOrFail(TournamentId::fromString($tournamentId));
        $rounds = $this->rounds->findByTournament($tournamentId);
        $matches = [];
        $roundNumbers = [];
        foreach ($rounds as $round) {
            $roundNumbers[$round->id()->value] = $round->roundNumber();
            array_push($matches, ...$this->matches->findByRound($round->id()->value));
        }

        $tiebreakers = $this->tiebreakers($tournament);
        $usesRandom = array_filter(
            $tiebreakers,
            static fn (TiebreakerDefinition $definition): bool => $definition->type === TiebreakerType::Random,
        ) !== [];

        $standings = $this->calculator->calculateWithRules(
            $this->rankedParticipants($tournamentId, $matches),
            $matches,
            $this->scoringRules($tournament),
            $tiebreakers,
            $roundNumbers,
            $usesRandom ? $this->randomSeed($tournamentId) : '',
        );

        DB::transaction(function () use ($tournamentId, $standings): void {
            $this->standings->deleteByTournament($tournamentId);
            $this->standings->saveMany($standings);
        });

        return $standings;
    }

    /**
     * The tournament's seed for the random tiebreaker, created and stored the first time it is needed.
     */
    private function randomSeed(string $tournamentId): string
    {
        $seed = TournamentModel::query()->whereKey($tournamentId)->value('random_seed');
        if (is_string($seed) && $seed !== '') {
            return $seed;
        }

        $seed = bin2hex(random_bytes(16));
        TournamentModel::query()->whereKey($tournamentId)->update(['random_seed' => $seed]);

        return $seed;
    }

    /**
     * Participants who can play, plus anyone who already played (a withdrawn player keeps their record).
     *
     * @param  array<TournamentMatch>  $matches
     * @return array<Participant>
     */
    private function rankedParticipants(string $tournamentId, array $matches): array
    {
        $played = [];
        foreach ($matches as $match) {
            $played[$match->player1Id()] = true;
            if ($match->player2Id() !== null) {
                $played[$match->player2Id()] = true;
            }
        }

        return array_values(array_filter(
            $this->participants->findByTournament($tournamentId),
            static fn (Participant $participant): bool => $participant->canPlay() || isset($played[$participant->id()->value]),
        ));
    }

    /**
     * The tournament's rules, else its game profile's, else the panel's default rules.
     *
     * @return array<ScoringRule>
     */
    private function scoringRules(Tournament $tournament): array
    {
        $rules = $tournament->scoringRules() ?? [];
        if ($rules === [] && $tournament->gameProfileId() !== null) {
            $rules = $this->gameProfiles->find(GameProfileId::fromString($tournament->gameProfileId()))?->scoringRules() ?? [];
        }

        if ($rules !== []) {
            return $rules;
        }

        return array_map(
            ScoringRule::fromArray(...),
            [
                ['name' => 'Victoria', 'points' => 3, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Empate', 'points' => 1, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'draw', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Derrota', 'points' => 0, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'loss', 'stat' => null, 'operator' => null, 'value' => null]],
                ['name' => 'Bye', 'points' => 3, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'bye', 'stat' => null, 'operator' => null, 'value' => null]],
            ],
        );
    }

    /**
     * The tournament's tiebreakers, else its game profile's, else Buchholz and progressive.
     *
     * @return array<TiebreakerDefinition>
     */
    private function tiebreakers(Tournament $tournament): array
    {
        $tiebreakers = $tournament->tiebreakerConfig() ?? [];
        if ($tiebreakers === [] && $tournament->gameProfileId() !== null) {
            $tiebreakers = $this->gameProfiles->find(GameProfileId::fromString($tournament->gameProfileId()))?->tiebreakerConfig() ?? [];
        }

        if ($tiebreakers !== []) {
            return $tiebreakers;
        }

        return [
            new TiebreakerDefinition('buchholz', 'Buchholz', TiebreakerType::Buchholz),
            new TiebreakerDefinition('progressive', 'Progresivo', TiebreakerType::Progressive),
        ];
    }
}
