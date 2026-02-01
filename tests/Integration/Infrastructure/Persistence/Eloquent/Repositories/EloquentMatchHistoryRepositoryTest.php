<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Persistence\Eloquent\Repositories;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Domain\Entities\MatchHistory;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Repositories\MatchHistoryRepositoryInterface;
use Modules\Tournaments\Domain\ValueObjects\MatchId;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchHistoryModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentMatchHistoryRepository;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

final class EloquentMatchHistoryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EloquentMatchHistoryRepository $repository;

    private MatchModel $match;

    private UserModel $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentMatchHistoryRepository();

        $event = EventModel::factory()->create();
        $tournament = TournamentModel::create([
            'event_id' => $event->id,
            'name' => 'Test Tournament',
            'slug' => 'test-tournament',
            'status' => 'in_progress',
            'result_reporting' => 'admin_only',
        ]);

        $round = RoundModel::create([
            'tournament_id' => $tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);

        $users = UserModel::factory()->count(2)->create();
        $participant1 = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $users[0]->id,
            'status' => 'confirmed',
        ]);
        $participant2 = ParticipantModel::create([
            'tournament_id' => $tournament->id,
            'user_id' => $users[1]->id,
            'status' => 'confirmed',
        ]);

        $this->match = MatchModel::create([
            'round_id' => $round->id,
            'player_1_id' => $participant1->id,
            'player_2_id' => $participant2->id,
            'result' => 'player_one_win',
        ]);

        $this->user = UserModel::factory()->create();
    }

    public function test_it_implements_match_history_repository_interface(): void
    {
        $this->assertInstanceOf(MatchHistoryRepositoryInterface::class, $this->repository);
    }

    public function test_it_saves_match_history_entry(): void
    {
        $history = MatchHistory::fromResultChange(
            matchId: $this->match->id,
            previousResult: MatchResult::NotPlayed,
            newResult: MatchResult::PlayerOneWin,
            previousPlayer1Score: null,
            newPlayer1Score: 3,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: $this->user->id,
            reason: 'Initial result report',
        );

        $this->repository->save($history);

        $this->assertDatabaseHas('tournaments_match_history', [
            'id' => $history->id(),
            'match_id' => $this->match->id,
            'previous_result' => 'not_played',
            'new_result' => 'player_one_win',
            'previous_player_1_score' => null,
            'new_player_1_score' => 3,
            'previous_player_2_score' => null,
            'new_player_2_score' => 1,
            'changed_by_id' => $this->user->id,
            'reason' => 'Initial result report',
        ]);
    }

    public function test_it_saves_history_without_previous_result(): void
    {
        $history = MatchHistory::fromResultChange(
            matchId: $this->match->id,
            previousResult: null,
            newResult: MatchResult::Draw,
            previousPlayer1Score: null,
            newPlayer1Score: 1,
            previousPlayer2Score: null,
            newPlayer2Score: 1,
            changedById: $this->user->id,
            reason: null,
        );

        $this->repository->save($history);

        $this->assertDatabaseHas('tournaments_match_history', [
            'id' => $history->id(),
            'previous_result' => null,
            'new_result' => 'draw',
            'reason' => null,
        ]);
    }

    public function test_it_finds_by_match(): void
    {
        // Create multiple history entries for the same match
        MatchHistoryModel::create([
            'id' => Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => null,
            'new_result' => 'player_one_win',
            'new_player_1_score' => 2,
            'new_player_2_score' => 0,
            'changed_by_id' => $this->user->id,
            'changed_at' => now()->subHour(),
        ]);

        MatchHistoryModel::create([
            'id' => Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => 'player_one_win',
            'new_result' => 'draw',
            'previous_player_1_score' => 2,
            'new_player_1_score' => 1,
            'previous_player_2_score' => 0,
            'new_player_2_score' => 1,
            'changed_by_id' => $this->user->id,
            'reason' => 'Score correction',
            'changed_at' => now(),
        ]);

        // Create history for different match
        $otherMatch = MatchModel::create([
            'round_id' => $this->match->round_id,
            'player_1_id' => $this->match->player_1_id,
            'player_2_id' => null,
            'result' => 'bye',
        ]);
        MatchHistoryModel::create([
            'id' => Uuid::uuid4()->toString(),
            'match_id' => $otherMatch->id,
            'previous_result' => null,
            'new_result' => 'bye',
            'changed_by_id' => $this->user->id,
            'changed_at' => now(),
        ]);

        $history = $this->repository->findByMatch($this->match->id);

        $this->assertCount(2, $history);
        foreach ($history as $entry) {
            $this->assertEquals($this->match->id, $entry->matchId());
        }
    }

    public function test_it_returns_empty_array_when_no_history(): void
    {
        $history = $this->repository->findByMatch($this->match->id);

        $this->assertIsArray($history);
        $this->assertEmpty($history);
    }

    public function test_it_orders_history_by_changed_at(): void
    {
        MatchHistoryModel::create([
            'id' => $firstId = Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => null,
            'new_result' => 'player_one_win',
            'changed_by_id' => $this->user->id,
            'changed_at' => now()->subHours(2),
        ]);

        MatchHistoryModel::create([
            'id' => $secondId = Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => 'player_one_win',
            'new_result' => 'draw',
            'changed_by_id' => $this->user->id,
            'changed_at' => now()->subHour(),
        ]);

        MatchHistoryModel::create([
            'id' => $thirdId = Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => 'draw',
            'new_result' => 'player_two_win',
            'changed_by_id' => $this->user->id,
            'changed_at' => now(),
        ]);

        $history = $this->repository->findByMatch($this->match->id);

        $this->assertCount(3, $history);
        $this->assertEquals($firstId, $history[0]->id());
        $this->assertEquals($secondId, $history[1]->id());
        $this->assertEquals($thirdId, $history[2]->id());
    }

    public function test_it_correctly_converts_to_entity(): void
    {
        $changedAt = now();

        MatchHistoryModel::create([
            'id' => $id = Uuid::uuid4()->toString(),
            'match_id' => $this->match->id,
            'previous_result' => 'not_played',
            'new_result' => 'player_one_win',
            'previous_player_1_score' => null,
            'new_player_1_score' => 3,
            'previous_player_2_score' => null,
            'new_player_2_score' => 0,
            'changed_by_id' => $this->user->id,
            'reason' => 'Test reason',
            'changed_at' => $changedAt,
        ]);

        $history = $this->repository->findByMatch($this->match->id);

        $this->assertCount(1, $history);
        $entry = $history[0];

        $this->assertEquals($id, $entry->id());
        $this->assertEquals($this->match->id, $entry->matchId());
        $this->assertEquals(MatchResult::NotPlayed, $entry->previousResult());
        $this->assertEquals(MatchResult::PlayerOneWin, $entry->newResult());
        $this->assertNull($entry->previousPlayer1Score());
        $this->assertEquals(3, $entry->newPlayer1Score());
        $this->assertNull($entry->previousPlayer2Score());
        $this->assertEquals(0, $entry->newPlayer2Score());
        $this->assertEquals($this->user->id, $entry->changedById());
        $this->assertEquals('Test reason', $entry->reason());
        $this->assertInstanceOf(DateTimeImmutable::class, $entry->changedAt());
    }
}
