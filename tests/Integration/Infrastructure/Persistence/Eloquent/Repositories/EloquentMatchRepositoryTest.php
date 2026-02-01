<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\TournamentMatch;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Exceptions\MatchNotFoundException;
use Modules\Tournaments\Domain\Repositories\MatchRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Domain\ValueObjects\ParticipantId;
use Modules\Tournaments\Domain\ValueObjects\RoundId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentMatchRepository;
use Tests\TestCase;

final class EloquentMatchRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentMatchRepository $repository;

    private TournamentModel $tournament;

    private RoundModel $round;

    private ParticipantModel $participant1;

    private ParticipantModel $participant2;

    private ParticipantModel $participant3;

    private ParticipantModel $participant4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentMatchRepository();

        $event = EventModel::factory()->create();
        $this->tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);

        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $users = UserModel::factory()->count(4)->create();
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
        $this->participant4 = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $users[3]->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_it_implements_match_repository_interface(): void
    {
        $this->assertInstanceOf(MatchRepositoryInterface::class, $this->repository);
    }

    public function test_it_saves_new_match(): void
    {
        $id = MatchId::generate();

        $match = new TournamentMatch(
            id: $id,
            roundId: $this->round->id,
            player1Id: $this->participant1->id,
            player2Id: $this->participant2->id,
            result: MatchResult::NotPlayed,
            tableNumber: 1,
        );

        $this->repository->save($match);

        $this->assertDatabaseHas('tournaments_matches', [
            'id' => $id->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
            'table_number' => 1,
        ]);
    }

    public function test_it_saves_bye_match(): void
    {
        $id = MatchId::generate();

        $match = new TournamentMatch(
            id: $id,
            roundId: $this->round->id,
            player1Id: $this->participant1->id,
            player2Id: null,
            result: MatchResult::Bye,
            tableNumber: null,
        );

        $this->repository->save($match);

        $this->assertDatabaseHas('tournaments_matches', [
            'id' => $id->value,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => null,
            'result' => 'bye',
        ]);
    }

    public function test_it_updates_existing_match(): void
    {
        $model = MatchModel::create([
            'id' => $id = MatchId::generate()->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);

        $match = new TournamentMatch(
            id: MatchId::fromString($id),
            roundId: $this->round->id,
            player1Id: $this->participant1->id,
            player2Id: $this->participant2->id,
            result: MatchResult::PlayerOneWin,
            player1Score: 3,
            player2Score: 1,
            reportedById: 'some-user-id',
            reportedAt: new DateTimeImmutable(),
        );

        $this->repository->save($match);

        $this->assertDatabaseHas('tournaments_matches', [
            'id' => $id,
            'result' => 'player_one_win',
            'player_1_score' => 3,
            'player_2_score' => 1,
        ]);
    }

    public function test_it_saves_many_matches(): void
    {
        $matches = [
            new TournamentMatch(
                id: MatchId::generate(),
                roundId: $this->round->id,
                player1Id: $this->participant1->id,
                player2Id: $this->participant2->id,
                result: MatchResult::NotPlayed,
                tableNumber: 1,
            ),
            new TournamentMatch(
                id: MatchId::generate(),
                roundId: $this->round->id,
                player1Id: $this->participant3->id,
                player2Id: $this->participant4->id,
                result: MatchResult::NotPlayed,
                tableNumber: 2,
            ),
        ];

        $this->repository->saveMany($matches);

        $this->assertDatabaseCount('tournaments_matches', 2);
        $this->assertDatabaseHas('tournaments_matches', ['table_number' => 1]);
        $this->assertDatabaseHas('tournaments_matches', ['table_number' => 2]);
    }

    public function test_it_finds_by_id(): void
    {
        $model = MatchModel::create([
            'id' => $id = MatchId::generate()->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
            'table_number' => 3,
            'player_1_score' => 2,
            'player_2_score' => 0,
        ]);

        $match = $this->repository->find(MatchId::fromString($id));

        $this->assertNotNull($match);
        $this->assertEquals($id, $match->id()->value);
        $this->assertEquals($this->round->id, $match->roundId());
        $this->assertEquals($this->participant1->id, $match->player1Id());
        $this->assertEquals($this->participant2->id, $match->player2Id());
        $this->assertEquals(MatchResult::PlayerOneWin, $match->result());
        $this->assertEquals(3, $match->tableNumber());
        $this->assertEquals(2, $match->player1Score());
        $this->assertEquals(0, $match->player2Score());
    }

    public function test_it_returns_null_when_not_found(): void
    {
        $match = $this->repository->find(MatchId::generate());

        $this->assertNull($match);
    }

    public function test_find_or_fail_throws_exception_when_not_found(): void
    {
        $this->expectException(MatchNotFoundException::class);

        $this->repository->findOrFail(MatchId::generate());
    }

    public function test_it_finds_by_round(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant3->id,
            'player_2_id' => $this->participant4->id,
            'result' => 'not_played',
        ]);

        // Create match in different round
        $otherRound = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'pending',
        ]);
        MatchModel::create([
            'round_id' => $otherRound->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant3->id,
            'result' => 'not_played',
        ]);

        $matches = $this->repository->findByRound($this->round->id);

        $this->assertCount(2, $matches);
        foreach ($matches as $match) {
            $this->assertEquals($this->round->id, $match->roundId());
        }
    }

    public function test_it_finds_by_participant(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
        ]);

        $round2 = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'in_progress',
        ]);
        MatchModel::create([
            'round_id' => $round2->id,
            'player_1_id' => $this->participant3->id,
            'player_2_id' => $this->participant1->id,
            'result' => 'draw',
        ]);

        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant3->id,
            'player_2_id' => $this->participant4->id,
            'result' => 'not_played',
        ]);

        $matches = $this->repository->findByParticipant($this->participant1->id);

        $this->assertCount(2, $matches);
        foreach ($matches as $match) {
            $this->assertTrue($match->involvesParticipant($this->participant1->id));
        }
    }

    public function test_it_finds_by_participant_and_tournament(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
        ]);

        $round2 = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 2,
            'status' => 'in_progress',
        ]);
        MatchModel::create([
            'round_id' => $round2->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant3->id,
            'result' => 'draw',
        ]);

        // Create match in different tournament
        $otherEvent = EventModel::factory()->create();
        $otherTournament = TournamentModel::create([
            'event_id' => $otherEvent->id,
            'name' => 'Other Tournament',
            'slug' => 'other-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);
        $otherRound = RoundModel::create([
            'tournament_id' => $otherTournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $otherParticipant = ParticipantModel::create([
            'tournament_id' => $otherTournament->id,
            'user_id' => UserModel::factory()->create()->id,
            'status' => 'confirmed',
        ]);
        // This match should not be included (different tournament)
        MatchModel::create([
            'round_id' => $otherRound->id,
            'player_1_id' => $otherParticipant->id,
            'player_2_id' => null,
            'result' => 'bye',
        ]);

        $matches = $this->repository->findByParticipantAndTournament(
            $this->participant1->id,
            $this->tournament->id
        );

        $this->assertCount(2, $matches);
    }

    public function test_have_played_before_returns_true(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
        ]);

        $havePlayed = $this->repository->havePlayedBefore(
            $this->participant1->id,
            $this->participant2->id,
            $this->tournament->id
        );

        $this->assertTrue($havePlayed);
    }

    public function test_have_played_before_returns_true_reversed_order(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
        ]);

        // Check with reversed player order
        $havePlayed = $this->repository->havePlayedBefore(
            $this->participant2->id,
            $this->participant1->id,
            $this->tournament->id
        );

        $this->assertTrue($havePlayed);
    }

    public function test_have_played_before_returns_false(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'player_one_win',
        ]);

        $havePlayed = $this->repository->havePlayedBefore(
            $this->participant3->id,
            $this->participant4->id,
            $this->tournament->id
        );

        $this->assertFalse($havePlayed);
    }

    public function test_it_counts_unreported_by_round(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant3->id,
            'player_2_id' => $this->participant4->id,
            'result' => 'player_one_win',
        ]);

        $count = $this->repository->countUnreportedByRound($this->round->id);

        $this->assertEquals(1, $count);
    }

    public function test_it_finds_by_participant_and_round(): void
    {
        MatchModel::create([
            'id' => $matchId = MatchId::generate()->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);

        $match = $this->repository->findByParticipantAndRound(
            $this->participant1->id,
            $this->round->id
        );

        $this->assertNotNull($match);
        $this->assertEquals($matchId, $match->id()->value);
    }

    public function test_it_finds_by_participant_as_player2_and_round(): void
    {
        MatchModel::create([
            'id' => $matchId = MatchId::generate()->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);

        $match = $this->repository->findByParticipantAndRound(
            $this->participant2->id,
            $this->round->id
        );

        $this->assertNotNull($match);
        $this->assertEquals($matchId, $match->id()->value);
    }

    public function test_it_returns_null_when_participant_not_in_round(): void
    {
        MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);

        $match = $this->repository->findByParticipantAndRound(
            $this->participant3->id,
            $this->round->id
        );

        $this->assertNull($match);
    }

    public function test_it_deletes_match(): void
    {
        MatchModel::create([
            'id' => $id = MatchId::generate()->value,
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant1->id,
            'player_2_id' => $this->participant2->id,
            'result' => 'not_played',
        ]);

        $this->assertDatabaseHas('tournaments_matches', ['id' => $id]);

        $this->repository->delete(MatchId::fromString($id));

        $this->assertDatabaseMissing('tournaments_matches', ['id' => $id]);
    }

    public function test_it_handles_confirmation_fields(): void
    {
        $id = MatchId::generate();
        $reportedAt = new DateTimeImmutable('2026-02-15 10:00:00');
        $confirmedAt = new DateTimeImmutable('2026-02-15 10:30:00');

        $match = new TournamentMatch(
            id: $id,
            roundId: $this->round->id,
            player1Id: $this->participant1->id,
            player2Id: $this->participant2->id,
            result: MatchResult::PlayerOneWin,
            reportedById: 'reporter-id',
            reportedAt: $reportedAt,
            confirmedById: 'confirmer-id',
            confirmedAt: $confirmedAt,
        );

        $this->repository->save($match);

        $retrieved = $this->repository->find($id);

        $this->assertNotNull($retrieved);
        $this->assertEquals('reporter-id', $retrieved->reportedById());
        $this->assertNotNull($retrieved->reportedAt());
        $this->assertEquals('confirmer-id', $retrieved->confirmedById());
        $this->assertNotNull($retrieved->confirmedAt());
        $this->assertTrue($retrieved->isConfirmed());
    }

    public function test_it_handles_disputed_flag(): void
    {
        $id = MatchId::generate();

        $match = new TournamentMatch(
            id: $id,
            roundId: $this->round->id,
            player1Id: $this->participant1->id,
            player2Id: $this->participant2->id,
            result: MatchResult::PlayerOneWin,
            isDisputed: true,
        );

        $this->repository->save($match);

        $retrieved = $this->repository->find($id);

        $this->assertNotNull($retrieved);
        $this->assertTrue($retrieved->isDisputed());
    }
}
