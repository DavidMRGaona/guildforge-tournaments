<?php

declare(strict_types=1);

namespace Modules\Tournaments\Infrastructure\Services;

use App\Domain\Surveys\Events\SurveySubjectCancelled;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Events\TournamentCancelled;
use Modules\Tournaments\Domain\Events\TournamentFinished;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Domain\Repositories\TournamentRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\TournamentId;
use Modules\Tournaments\Infrastructure\Surveys\TournamentSurveySubjectSource;

final readonly class TournamentLifecycleService implements TournamentLifecycleServiceInterface
{
    public function __construct(
        private TournamentRepositoryInterface $tournaments,
        private StandingRepositoryInterface $standings,
        private RegistrationLockInterface $lock,
    ) {}

    public function finish(string $tournamentId): void
    {
        // The tournament's row stays locked until the commit: a second click finds it finished
        $this->lock->forTournament($tournamentId, function () use ($tournamentId): void {
            $tournament = $this->tournaments->findOrFail(new TournamentId($tournamentId));
            $tournament->finish();
            $this->tournaments->save($tournament);

            $leader = $this->standings->findByTournamentOrderedByRank($tournamentId)[0] ?? null;
            $finished = TournamentFinished::create(
                $tournamentId,
                $tournament->currentRound(),
                $leader instanceof Standing ? $leader->participantId() : null,
            );
            $endedAt = $tournament->completedAt() ?? new DateTimeImmutable;

            DB::afterCommit(static function () use ($finished, $tournamentId, $endedAt): void {
                event($finished);
                event(new SurveySubjectEnded(TournamentSurveySubjectSource::TYPE, $tournamentId, $endedAt));
            });
        });
    }

    public function cancel(string $tournamentId): void
    {
        $this->lock->forTournament($tournamentId, function () use ($tournamentId): void {
            $tournament = $this->tournaments->findOrFail(new TournamentId($tournamentId));
            $previousStatus = $tournament->status();
            $tournament->cancel();
            $this->tournaments->save($tournament);

            $cancelled = TournamentCancelled::create($tournamentId, $previousStatus);

            DB::afterCommit(static function () use ($cancelled, $tournamentId): void {
                event($cancelled);
                event(new SurveySubjectCancelled(TournamentSurveySubjectSource::TYPE, $tournamentId, $cancelled->occurredAt));
            });
        });
    }
}
