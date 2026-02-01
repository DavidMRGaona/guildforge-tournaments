<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\Round;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Exceptions\RoundNotFoundException;
use Modules\Tournaments\Domain\Repositories\RoundRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentRoundRepository;
use Tests\TestCase;

final class EloquentRoundRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentRoundRepository $repository;

    private TournamentModel $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentRoundRepository();

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
    }

    public function test_it_implements_round_repository_interface(): void
    {
        $this->assertInstanceOf(RoundRepositoryInterface::class, $this->repository);
    }

    public function test_it_saves_new_round(): void
    {
        $id = RoundId::generate();

        $round = new Round(
            id: $id,
            tournamentId: $this->tournament->id,
            roundNumber: 1,
            status: RoundStatus::Pending,
        );

        $this->repository->save($round);

        $this->assertDatabaseHas('tournaments_rounds', [
            'id' => $id->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);
    }

    public function test_it_updates_existing_round(): void
    {
        $model = RoundModel::create([
            'id' => $id = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);

        $round = new Round(
            id: RoundId::fromString($id),
            tournamentId: $this->tournament->id,
            roundNumber: 1,
            status: RoundStatus::InProgress,
            startedAt: new DateTimeImmutable(),
        );

        $this->repository->save($round);

        $this->assertDatabaseHas('tournaments_rounds', [
            'id' => $id,
            'status' => 'in_progress',
        ]);
    }

    public function test_it_finds_by_id(): void
    {
        $model = RoundModel::create([
            'id' => $id = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'in_progress',
        ]);

        $round = $this->repository->find(RoundId::fromString($id));

        $this->assertNotNull($round);
        $this->assertEquals($id, $round->id()->value);
        $this->assertEquals($this->tournament->id, $round->tournamentId());
        $this->assertEquals(2, $round->roundNumber());
        $this->assertEquals(RoundStatus::InProgress, $round->status());
    }

    public function test_it_returns_null_when_not_found(): void
    {
        $round = $this->repository->find(RoundId::generate());

        $this->assertNull($round);
    }

    public function test_find_or_fail_throws_exception_when_not_found(): void
    {
        $this->expectException(RoundNotFoundException::class);

        $this->repository->findOrFail(RoundId::generate());
    }

    public function test_it_finds_by_tournament(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 3,
            'status' => 'in_progress',
        ]);

        // Create round in different tournament
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
        RoundModel::create([
            'tournament_id' => $otherTournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);

        $rounds = $this->repository->findByTournament($this->tournament->id);

        $this->assertCount(3, $rounds);
        foreach ($rounds as $round) {
            $this->assertEquals($this->tournament->id, $round->tournamentId());
        }
    }

    public function test_it_finds_current_round_in_progress(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'id' => $currentId = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'in_progress',
        ]);

        $currentRound = $this->repository->findCurrentRound($this->tournament->id);

        $this->assertNotNull($currentRound);
        $this->assertEquals($currentId, $currentRound->id()->value);
        $this->assertEquals(2, $currentRound->roundNumber());
        $this->assertEquals(RoundStatus::InProgress, $currentRound->status());
    }

    public function test_it_finds_current_round_pending(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'id' => $pendingId = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'pending',
        ]);

        $currentRound = $this->repository->findCurrentRound($this->tournament->id);

        $this->assertNotNull($currentRound);
        $this->assertEquals($pendingId, $currentRound->id()->value);
        $this->assertEquals(RoundStatus::Pending, $currentRound->status());
    }

    public function test_it_returns_null_when_no_current_round(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'finished',
        ]);

        $currentRound = $this->repository->findCurrentRound($this->tournament->id);

        $this->assertNull($currentRound);
    }

    public function test_it_finds_by_tournament_and_number(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);
        RoundModel::create([
            'id' => $round2Id = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'in_progress',
        ]);
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 3,
            'status' => 'pending',
        ]);

        $round = $this->repository->findByTournamentAndNumber($this->tournament->id, 2);

        $this->assertNotNull($round);
        $this->assertEquals($round2Id, $round->id()->value);
        $this->assertEquals(2, $round->roundNumber());
    }

    public function test_it_returns_null_when_round_number_not_found(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
        ]);

        $round = $this->repository->findByTournamentAndNumber($this->tournament->id, 5);

        $this->assertNull($round);
    }

    public function test_it_finds_latest_completed_round(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'finished',
            'completed_at' => now()->subHours(2),
        ]);
        RoundModel::create([
            'id' => $latestId = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'finished',
            'completed_at' => now()->subHour(),
        ]);
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 3,
            'status' => 'in_progress',
        ]);

        $latestCompleted = $this->repository->findLatestCompletedRound($this->tournament->id);

        $this->assertNotNull($latestCompleted);
        $this->assertEquals($latestId, $latestCompleted->id()->value);
        $this->assertEquals(2, $latestCompleted->roundNumber());
    }

    public function test_it_returns_null_when_no_completed_rounds(): void
    {
        RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);

        $latestCompleted = $this->repository->findLatestCompletedRound($this->tournament->id);

        $this->assertNull($latestCompleted);
    }

    public function test_it_deletes_round(): void
    {
        RoundModel::create([
            'id' => $id = RoundId::generate()->value,
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('tournaments_rounds', ['id' => $id]);

        $this->repository->delete(RoundId::fromString($id));

        $this->assertDatabaseMissing('tournaments_rounds', ['id' => $id]);
    }

    public function test_it_handles_timestamps_correctly(): void
    {
        $startedAt = new DateTimeImmutable('2026-02-15 10:00:00');
        $completedAt = new DateTimeImmutable('2026-02-15 12:30:00');
        $id = RoundId::generate();

        $round = new Round(
            id: $id,
            tournamentId: $this->tournament->id,
            roundNumber: 1,
            status: RoundStatus::Finished,
            startedAt: $startedAt,
            completedAt: $completedAt,
        );

        $this->repository->save($round);

        $retrieved = $this->repository->find($id);

        $this->assertNotNull($retrieved);
        $this->assertNotNull($retrieved->startedAt());
        $this->assertNotNull($retrieved->completedAt());
        $this->assertEquals('2026-02-15', $retrieved->startedAt()->format('Y-m-d'));
        $this->assertEquals('2026-02-15', $retrieved->completedAt()->format('Y-m-d'));
    }
}
