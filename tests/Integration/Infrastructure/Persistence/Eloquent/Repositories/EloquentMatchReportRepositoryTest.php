<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentMatchReportRepository;
use Modules\Tournaments\Tests\Support\AssertsContainerBindings;
use Modules\Tournaments\TournamentsServiceProvider;
use Tests\TestCase;

final class EloquentMatchReportRepositoryTest extends TestCase
{
    use AssertsContainerBindings;
    use RefreshDatabase;

    private EloquentMatchReportRepository $repository;

    private RoundModel $round;

    private MatchModel $match;

    /** @var array<UserModel> */
    private array $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentMatchReportRepository;

        $tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
        ]);

        $this->round = RoundModel::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $this->users = UserModel::factory()->count(2)->create()->all();
        $this->match = $this->createMatch($tournament);
    }

    public function test_container_resolves_match_report_repository_interface_to_eloquent_repository(): void
    {
        $this->app->register(TournamentsServiceProvider::class);

        $this->assertContainerBinds(MatchReportRepositoryInterface::class, EloquentMatchReportRepository::class);
    }

    public function test_saving_twice_keeps_one_report_per_match(): void
    {
        $report = $this->report($this->match->id);
        $this->repository->save($report);

        $report->correct(MatchResult::PlayerTwoWin, 10, 12, ['vp' => 10], ['vp' => 12], new DateTimeImmutable('2030-05-01 11:00:00'));
        $this->repository->save($report);

        $this->assertDatabaseCount('tournaments_match_reports', 1);

        $found = $this->repository->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $found);
        $this->assertSame(MatchResult::PlayerTwoWin, $found->result());
        $this->assertSame(10, $found->player1Score());
        $this->assertSame(12, $found->player2Score());
        $this->assertSame(['vp' => 10], $found->player1Stats());
        $this->assertSame(['vp' => 12], $found->player2Stats());
        $this->assertSame($this->users[0]->id, $found->reportedById());
        $this->assertSame('2030-05-01 11:00:00', $found->reportedAt()->format('Y-m-d H:i:s'));
    }

    public function test_find_by_round_keys_reports_by_match_id(): void
    {
        $tournament = TournamentModel::query()->firstOrFail();
        $other = $this->createMatch($tournament);
        $this->repository->save($this->report($this->match->id));
        $this->repository->save($this->report($other->id));

        $reports = $this->repository->findByRound($this->round->id);

        $this->assertEqualsCanonicalizing([$this->match->id, $other->id], array_keys($reports));
        $this->assertSame($other->id, $reports[$other->id]->matchId());
    }

    public function test_dispute_is_stored_and_correcting_clears_it(): void
    {
        $report = $this->report($this->match->id);
        $report->dispute($this->users[1]->id, 'It was a draw', new DateTimeImmutable('2030-05-01 10:30:00'));
        $this->repository->save($report);

        $disputed = $this->repository->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $disputed);
        $this->assertTrue($disputed->isDisputed());
        $this->assertSame($this->users[1]->id, $disputed->disputedById());
        $this->assertSame('It was a draw', $disputed->disputeReason());
        $this->assertSame('2030-05-01 10:30:00', $disputed->disputedAt()?->format('Y-m-d H:i:s'));

        $disputed->correct(MatchResult::Draw, null, null, null, null, new DateTimeImmutable('2030-05-01 10:40:00'));
        $this->repository->save($disputed);

        $corrected = $this->repository->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $corrected);
        $this->assertFalse($corrected->isDisputed());
        $this->assertNull($corrected->disputeReason());
    }

    public function test_delete_removes_the_report(): void
    {
        $this->repository->save($this->report($this->match->id));

        $this->repository->delete($this->match->id);

        $this->assertNull($this->repository->find($this->match->id));
    }

    public function test_count_by_tournament_counts_pending_and_disputed_reports_of_that_tournament_only(): void
    {
        $tournament = TournamentModel::query()->firstOrFail();
        $disputed = $this->report($this->createMatch($tournament)->id);
        $disputed->dispute($this->users[1]->id, 'It was a draw', new DateTimeImmutable('2030-05-01 10:30:00'));
        $this->repository->save($this->report($this->match->id));
        $this->repository->save($disputed);
        $this->repository->save($this->report($this->matchOfAnotherTournament()->id));

        $this->assertSame(2, $this->repository->countByTournament($tournament->id));
    }

    public function test_delete_by_tournament_leaves_the_reports_of_other_tournaments(): void
    {
        $tournament = TournamentModel::query()->firstOrFail();
        $elsewhere = $this->matchOfAnotherTournament();
        $this->repository->save($this->report($this->match->id));
        $this->repository->save($this->report($elsewhere->id));

        $this->repository->deleteByTournament($tournament->id);

        $this->assertNull($this->repository->find($this->match->id));
        $this->assertInstanceOf(MatchReport::class, $this->repository->find($elsewhere->id));
    }

    private function report(string $matchId): MatchReport
    {
        return new MatchReport(
            matchId: $matchId,
            result: MatchResult::PlayerOneWin,
            reportedById: $this->users[0]->id,
            reportedAt: new DateTimeImmutable('2030-05-01 10:00:00'),
            player1Score: 20,
            player2Score: 15,
        );
    }

    private function createMatch(TournamentModel $tournament, ?RoundModel $round = null): MatchModel
    {
        $one = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
        $two = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);

        return MatchModel::create([
            'round_id' => ($round ?? $this->round)->id,
            'player_1_id' => $one->id,
            'player_2_id' => $two->id,
            'result' => 'not_played',
        ]);
    }

    private function matchOfAnotherTournament(): MatchModel
    {
        $tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
        ]);
        $round = RoundModel::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        return $this->createMatch($tournament, $round);
    }
}
