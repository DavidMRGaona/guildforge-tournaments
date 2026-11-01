<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\DTOs\Response\PlayerMatchResponseDTO;
use Modules\Tournaments\Application\Services\MatchQueryServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ResultReporting;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The player's own match of the current round, with what they may do with its result.
 */
final class MatchQueryServicePlayerMatchTest extends ModuleTestCase
{
    use PinsTheClock;

    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentModel $tournament;

    private RoundModel $round;

    private MatchModel $match;

    private UserModel $one;

    private UserModel $two;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tournament = TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => 'players_with_confirmation',
            'stat_definitions' => [
                ['key' => 'vp', 'name' => 'Puntos de victoria', 'type' => 'integer', 'min_value' => 0, 'max_value' => 100, 'per_player' => true, 'required' => true],
            ],
        ]);
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $this->one = UserModel::factory()->create(['name' => 'Ana']);
        $this->two = UserModel::factory()->create(['name' => 'Luis']);
        $this->match = MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $this->participant($this->one)->id,
            'player_2_id' => $this->participant($this->two)->id,
            'table_number' => 3,
            'result' => 'not_played',
        ]);
    }

    public function test_player_match_offers_the_actions_of_the_rules(): void
    {
        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame($this->match->id, $mine->match->id);
        $this->assertSame(1, $mine->side);
        $this->assertSame('Luis', $mine->opponentName);
        $this->assertFalse($mine->opponentIsGuest);
        $this->assertTrue($mine->actions->canReport);
        $this->assertNull($mine->report);
        $this->assertSame(ResultReporting::PlayersWithConfirmation, $mine->resultReporting);
        $this->assertSame(['vp'], array_map(static fn ($stat): string => $stat->key, $mine->statDefinitions));

        $report = new MatchReport($this->match->id, MatchResult::PlayerOneWin, $this->one->id, new DateTimeImmutable('2026-06-10 11:00:00'), 20, 15);
        $this->reports()->save($report);

        $theirs = $this->queries()->findPlayerMatch($this->tournament->id, $this->two->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $theirs);
        $this->assertSame(2, $theirs->side);
        $this->assertSame('Ana', $theirs->opponentName);
        $this->assertTrue($theirs->actions->canConfirm);
        $this->assertTrue($theirs->actions->canDispute);
        $this->assertFalse($theirs->actions->canReport);
        $this->assertNotNull($theirs->report);
        $this->assertSame('Ana', $theirs->report->reportedByName);
        $this->assertSame($this->reports()->find($this->match->id)?->fingerprint(), $theirs->report->fingerprint);
        $this->assertSame('pending', $theirs->toArray()['report']['status']);
    }

    public function test_player_match_is_null_without_round_in_progress_or_for_guests(): void
    {
        $this->assertNull($this->queries()->findPlayerMatch($this->tournament->id, UserModel::factory()->create()->id));

        $this->round->update(['status' => 'finished']);

        $this->assertNull($this->queries()->findPlayerMatch($this->tournament->id, $this->one->id));
    }

    public function test_a_guest_opponent_is_flagged(): void
    {
        $guest = ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'guest_name' => 'Invitada',
            'guest_email' => 'invitada@example.com',
            'status' => 'confirmed',
        ]);
        $this->match->update(['player_2_id' => $guest->id]);

        $mine = $this->queries()->findPlayerMatch($this->tournament->id, $this->one->id);

        $this->assertInstanceOf(PlayerMatchResponseDTO::class, $mine);
        $this->assertSame('Invitada', $mine->opponentName);
        $this->assertTrue($mine->opponentIsGuest);
    }

    public function test_history_lists_entries_with_names_and_actions(): void
    {
        $admin = UserModel::factory()->create(['name' => 'Organización']);
        app(MatchResultServiceInterface::class)->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, $admin->id));
        $this->travel(5)->minutes();
        app(MatchResultServiceInterface::class)->resetResult($this->match->id, $admin->id, 'Wrong table');

        $history = $this->queries()->getHistory($this->match->id);

        $this->assertCount(2, $history);
        $this->assertSame([MatchHistoryAction::Recorded, MatchHistoryAction::Reset], array_map(static fn ($entry): MatchHistoryAction => $entry->action, $history));
        $this->assertSame('Organización', $history[0]->changedByName);
        $this->assertSame('Wrong table', $history[1]->reason);
    }

    private function participant(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    private function queries(): MatchQueryServiceInterface
    {
        return app(MatchQueryServiceInterface::class);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return app(MatchReportRepositoryInterface::class);
    }
}
