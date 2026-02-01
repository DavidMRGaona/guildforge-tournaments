<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\Standing;
use Modules\Tournaments\Domain\Repositories\StandingRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentStandingRepository;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class EloquentStandingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentStandingRepository $repository;

    private TournamentModel $tournament;

    private ParticipantModel $participant1;

    private ParticipantModel $participant2;

    private ParticipantModel $participant3;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentStandingRepository();

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);

        $users = UserModel::factory()->count(3)->create();
        $this->participant1 = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[0]->id,
            'status' => 'confirmed',
        ]);
        $this->participant2 = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[1]->id,
            'status' => 'confirmed',
        ]);
        $this->participant3 = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[2]->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_it_implements_standing_repository_interface(): void
    {
        $this->assertInstanceOf(StandingRepositoryInterface::class, $this->repository);
    }

    public function test_it_saves_new_standing(): void
    {
        $id = Uuid::uuid4()->toString();

        $standing = new Standing(
            id: $id,
            tournamentId: $this->tournament->id,
            participantId: $this->participant1->id,
            rank: 1,
            matchesPlayed: 3,
            wins: 2,
            draws: 1,
            losses: 0,
            byes: 0,
            points: 7.0,
            buchholz: 12.5,
            medianBuchholz: 10.0,
            progressive: 15.0,
            opponentWinPercentage: 0.6667,
        );

        $this->repository->save($standing);

        $this->assertDatabaseHas('tournaments_standings', [
            'id' => $id,
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'matches_played' => 3,
            'wins' => 2,
            'draws' => 1,
            'losses' => 0,
            'byes' => 0,
            'points' => 7.0,
        ]);
    }

    public function test_it_updates_existing_standing(): void
    {
        $model = StandingModel::create([
            'id' => $id = Uuid::uuid4()->toString(),
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'matches_played' => 2,
            'wins' => 2,
            'draws' => 0,
            'losses' => 0,
            'byes' => 0,
            'points' => 6.0,
        ]);

        $standing = new Standing(
            id: $id,
            tournamentId: $this->tournament->id,
            participantId: $this->participant1->id,
            rank: 1,
            matchesPlayed: 3,
            wins: 2,
            draws: 1,
            losses: 0,
            byes: 0,
            points: 7.0,
        );

        $this->repository->save($standing);

        $this->assertDatabaseHas('tournaments_standings', [
            'id' => $id,
            'matches_played' => 3,
            'draws' => 1,
            'points' => 7.0,
        ]);
    }

    public function test_it_saves_many_standings(): void
    {
        $standings = [
            new Standing(
                id: Uuid::uuid4()->toString(),
                tournamentId: $this->tournament->id,
                participantId: $this->participant1->id,
                rank: 1,
                matchesPlayed: 2,
                wins: 2,
                draws: 0,
                losses: 0,
                byes: 0,
                points: 6.0,
            ),
            new Standing(
                id: Uuid::uuid4()->toString(),
                tournamentId: $this->tournament->id,
                participantId: $this->participant2->id,
                rank: 2,
                matchesPlayed: 2,
                wins: 1,
                draws: 0,
                losses: 1,
                byes: 0,
                points: 3.0,
            ),
            new Standing(
                id: Uuid::uuid4()->toString(),
                tournamentId: $this->tournament->id,
                participantId: $this->participant3->id,
                rank: 3,
                matchesPlayed: 2,
                wins: 0,
                draws: 0,
                losses: 2,
                byes: 0,
                points: 0.0,
            ),
        ];

        $this->repository->saveMany($standings);

        $this->assertDatabaseCount('tournaments_standings', 3);
        $this->assertDatabaseHas('tournaments_standings', ['rank' => 1, 'points' => 6.0]);
        $this->assertDatabaseHas('tournaments_standings', ['rank' => 2, 'points' => 3.0]);
        $this->assertDatabaseHas('tournaments_standings', ['rank' => 3, 'points' => 0.0]);
    }

    public function test_it_finds_by_tournament(): void
    {
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'points' => 6.0,
        ]);
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant2->id,
            'rank' => 2,
            'points' => 3.0,
        ]);

        // Create standing in different tournament
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
        $otherParticipant = ParticipantModel::create([
            'tournament_id' => $otherTournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
        StandingModel::create([
            'tournament_id' => $otherTournament->id,
            'participant_id' => $otherParticipant->id,
            'rank' => 1,
            'points' => 9.0,
        ]);

        $standings = $this->repository->findByTournament($this->tournament->id);

        $this->assertCount(2, $standings);
        foreach ($standings as $standing) {
            $this->assertEquals($this->tournament->id, $standing->tournamentId());
        }
    }

    public function test_it_finds_by_tournament_ordered_by_rank(): void
    {
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant2->id,
            'rank' => 2,
            'points' => 3.0,
        ]);
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'points' => 6.0,
        ]);
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant3->id,
            'rank' => 3,
            'points' => 0.0,
        ]);

        $standings = $this->repository->findByTournamentOrderedByRank($this->tournament->id);

        $this->assertCount(3, $standings);
        $this->assertEquals(1, $standings[0]->rank());
        $this->assertEquals(2, $standings[1]->rank());
        $this->assertEquals(3, $standings[2]->rank());
    }

    public function test_it_finds_by_participant(): void
    {
        StandingModel::create([
            'id' => $standingId = Uuid::uuid4()->toString(),
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'points' => 6.0,
        ]);

        $standing = $this->repository->findByParticipant($this->participant1->id);

        $this->assertNotNull($standing);
        $this->assertEquals($standingId, $standing->id());
        $this->assertEquals($this->participant1->id, $standing->participantId());
    }

    public function test_it_returns_null_when_participant_not_found(): void
    {
        $standing = $this->repository->findByParticipant('non-existent-id');

        $this->assertNull($standing);
    }

    public function test_it_finds_by_participant_and_tournament(): void
    {
        StandingModel::create([
            'id' => $standingId = Uuid::uuid4()->toString(),
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'points' => 6.0,
        ]);

        // Create standing for same participant in different tournament
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
        StandingModel::create([
            'tournament_id' => $otherTournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 2,
            'points' => 4.0,
        ]);

        $standing = $this->repository->findByParticipantAndTournament(
            $this->participant1->id,
            $this->tournament->id
        );

        $this->assertNotNull($standing);
        $this->assertEquals($standingId, $standing->id());
        $this->assertEquals($this->tournament->id, $standing->tournamentId());
    }

    public function test_it_deletes_by_tournament(): void
    {
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant1->id,
            'rank' => 1,
            'points' => 6.0,
        ]);
        StandingModel::create([
            'tournament_id' => $this->tournament->id,
            'participant_id' => $this->participant2->id,
            'rank' => 2,
            'points' => 3.0,
        ]);

        // Create standing in different tournament that should not be deleted
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
        $otherParticipant = ParticipantModel::create([
            'tournament_id' => $otherTournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
        StandingModel::create([
            'id' => $keepId = Uuid::uuid4()->toString(),
            'tournament_id' => $otherTournament->id,
            'participant_id' => $otherParticipant->id,
            'rank' => 1,
            'points' => 9.0,
        ]);

        $this->repository->deleteByTournament($this->tournament->id);

        $this->assertDatabaseCount('tournaments_standings', 1);
        $this->assertDatabaseHas('tournaments_standings', ['id' => $keepId]);
        $this->assertDatabaseMissing('tournaments_standings', [
            'tournament_id' => $this->tournament->id,
        ]);
    }

    public function test_it_correctly_converts_tiebreaker_values(): void
    {
        $id = Uuid::uuid4()->toString();

        $standing = new Standing(
            id: $id,
            tournamentId: $this->tournament->id,
            participantId: $this->participant1->id,
            rank: 1,
            matchesPlayed: 4,
            wins: 3,
            draws: 0,
            losses: 1,
            byes: 0,
            points: 9.0,
            buchholz: 15.5,
            medianBuchholz: 12.0,
            progressive: 21.0,
            opponentWinPercentage: 0.7500,
        );

        $this->repository->save($standing);

        $retrieved = $this->repository->findByParticipant($this->participant1->id);

        $this->assertNotNull($retrieved);
        $this->assertEquals(15.5, $retrieved->buchholz());
        $this->assertEquals(12.0, $retrieved->medianBuchholz());
        $this->assertEquals(21.0, $retrieved->progressive());
        $this->assertEquals(0.7500, $retrieved->opponentWinPercentage());
    }

    public function test_it_handles_bye_count(): void
    {
        $id = Uuid::uuid4()->toString();

        $standing = new Standing(
            id: $id,
            tournamentId: $this->tournament->id,
            participantId: $this->participant1->id,
            rank: 1,
            matchesPlayed: 5,
            wins: 3,
            draws: 0,
            losses: 1,
            byes: 1,
            points: 12.0,
        );

        $this->repository->save($standing);

        $retrieved = $this->repository->findByParticipant($this->participant1->id);

        $this->assertNotNull($retrieved);
        $this->assertEquals(1, $retrieved->byes());
        $this->assertEquals(5, $retrieved->matchesPlayed());
    }
}
