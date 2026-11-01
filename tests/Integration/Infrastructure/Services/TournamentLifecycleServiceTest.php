<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\TournamentStarted;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\PendingMatchReportsException;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Opening and closing registration and starting go through the tournament's state machine,
 * with its row locked, and the start is announced once and after the commit. Finishing waits
 * for the results the players reported; cancelling discards them.
 */
final class TournamentLifecycleServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    private TournamentLifecycleServiceInterface $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TournamentStarted::class]);
        $this->lifecycle = $this->app->make(TournamentLifecycleServiceInterface::class);
    }

    public function test_opening_registration_moves_a_draft_to_registration_open(): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);

        $this->lifecycle->openRegistration($tournament->id);

        $this->assertSame(TournamentStatus::RegistrationOpen, $tournament->refresh()->status);
    }

    public function test_opening_registration_on_a_cancelled_tournament_is_an_invalid_transition(): void
    {
        $tournament = $this->tournament(TournamentStatus::Cancelled);

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->lifecycle->openRegistration($tournament->id);
        } finally {
            $this->assertSame(TournamentStatus::Cancelled, $tournament->refresh()->status);
        }
    }

    public function test_closing_registration_moves_open_to_closed(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);

        $this->lifecycle->closeRegistration($tournament->id);

        $this->assertSame(TournamentStatus::RegistrationClosed, $tournament->refresh()->status);
    }

    public function test_closing_a_draft_is_an_invalid_transition(): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->lifecycle->closeRegistration($tournament->id);
        } finally {
            $this->assertSame(TournamentStatus::Draft, $tournament->refresh()->status);
        }
    }

    public function test_starting_moves_closed_registration_in_progress_and_sets_started_at(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);

        $this->lifecycle->start($tournament->id);

        $tournament->refresh();
        $this->assertSame(TournamentStatus::InProgress, $tournament->status);
        $this->assertNotNull($tournament->started_at);
    }

    public function test_starting_from_open_registration_works(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationOpen);
        $this->participants($tournament, 2, ParticipantStatus::Registered);

        $this->lifecycle->start($tournament->id);

        $this->assertSame(TournamentStatus::InProgress, $tournament->refresh()->status);
    }

    public function test_starting_announces_tournament_started(): void
    {
        $limited = $this->tournament(TournamentStatus::RegistrationClosed, ['max_rounds' => 4]);
        $this->participants($limited, 3, ParticipantStatus::Confirmed);
        $this->participants($limited, 1, ParticipantStatus::Withdrawn);
        $unlimited = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->participants($unlimited, 2, ParticipantStatus::CheckedIn);

        $this->lifecycle->start($limited->id);
        $this->lifecycle->start($unlimited->id);

        Event::assertDispatched(TournamentStarted::class, fn (TournamentStarted $event): bool => $event->tournamentId === $limited->id
            && $event->participantCount === 3
            && $event->plannedRounds === 4);
        Event::assertDispatched(TournamentStarted::class, fn (TournamentStarted $event): bool => $event->tournamentId === $unlimited->id
            && $event->participantCount === 2
            && $event->plannedRounds === null);
    }

    public function test_starting_below_the_minimum_throws_and_changes_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed, ['min_participants' => 4]);
        $this->participants($tournament, 3, ParticipantStatus::Confirmed);

        $this->expectException(InsufficientParticipantsException::class);

        try {
            $this->lifecycle->start($tournament->id);
        } finally {
            $tournament->refresh();
            $this->assertSame(TournamentStatus::RegistrationClosed, $tournament->status);
            $this->assertNull($tournament->started_at);
            Event::assertNotDispatched(TournamentStarted::class);
        }
    }

    public function test_withdrawn_and_disqualified_participants_do_not_count_towards_the_minimum(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed, ['min_participants' => 2]);
        $this->participants($tournament, 1, ParticipantStatus::Confirmed);
        $this->participants($tournament, 1, ParticipantStatus::Withdrawn);
        $this->participants($tournament, 1, ParticipantStatus::Disqualified);
        $this->participants($tournament, 1, ParticipantStatus::Expired);

        $this->expectException(InsufficientParticipantsException::class);

        $this->lifecycle->start($tournament->id);
    }

    public function test_starting_twice_announces_once(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);
        $this->lifecycle->start($tournament->id);

        try {
            $this->lifecycle->start($tournament->id);
            $this->fail('A tournament in progress cannot start again.');
        } catch (InvalidStateTransitionException) {
            // Expected: the second click finds it already started
        }

        Event::assertDispatchedTimes(TournamentStarted::class, 1);
    }

    public function test_a_rolled_back_start_announces_nothing(): void
    {
        $tournament = $this->tournament(TournamentStatus::RegistrationClosed);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);

        try {
            DB::transaction(function () use ($tournament): void {
                $this->lifecycle->start($tournament->id);

                throw new RuntimeException('Something later in the same transaction failed.');
            });
        } catch (RuntimeException) {
            // Expected
        }

        $this->assertSame(TournamentStatus::RegistrationClosed, $tournament->refresh()->status);
        Event::assertNotDispatched(TournamentStarted::class);
    }

    public function test_a_draft_cannot_start(): void
    {
        $tournament = $this->tournament(TournamentStatus::Draft);
        $this->participants($tournament, 2, ParticipantStatus::Confirmed);

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->lifecycle->start($tournament->id);
        } finally {
            $this->assertSame(TournamentStatus::Draft, $tournament->refresh()->status);
            Event::assertNotDispatched(TournamentStarted::class);
        }
    }

    public function test_finishing_with_a_report_waiting_for_confirmation_throws_and_keeps_it_in_progress(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $this->report($tournament);

        try {
            $this->lifecycle->finish($tournament->id);
            $this->fail('A tournament with a pending report was finished.');
        } catch (PendingMatchReportsException $exception) {
            $this->assertSame(1, $exception->count);
        }

        $this->assertSame(TournamentStatus::InProgress, $tournament->refresh()->status);
        $this->assertSame(1, $this->reports()->countByTournament($tournament->id));
    }

    public function test_finishing_with_a_disputed_report_throws(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $this->report($tournament, disputed: true);

        $this->expectException(PendingMatchReportsException::class);

        $this->lifecycle->finish($tournament->id);
    }

    public function test_finishing_without_reports_finishes(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);

        $this->lifecycle->finish($tournament->id);

        $this->assertSame(TournamentStatus::Finished, $tournament->refresh()->status);
    }

    public function test_cancelling_discards_the_pending_and_disputed_reports(): void
    {
        $tournament = $this->tournament(TournamentStatus::InProgress);
        $this->report($tournament);
        $this->report($tournament, disputed: true);

        $this->lifecycle->cancel($tournament->id);

        $this->assertSame(TournamentStatus::Cancelled, $tournament->refresh()->status);
        $this->assertSame(0, $this->reports()->countByTournament($tournament->id));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(TournamentStatus $status, array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create(['is_published' => true])->id,
            'name' => 'Torneo de ejemplo',
            'slug' => 'torneo-de-ejemplo-'.Str::random(6),
            'status' => $status->value,
            'result_reporting' => 'admin_only',
            'notification_email' => 'organizacion@example.com',
            ...$attributes,
        ])->refresh();
    }

    private function participants(TournamentModel $tournament, int $count, ParticipantStatus $status): void
    {
        for ($i = 0; $i < $count; $i++) {
            ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'user_id' => UserModel::factory()->create()->id,
                'status' => $status->value,
                'registered_at' => now(),
            ]);
        }
    }

    /**
     * A report of a match of the tournament's current round, waiting for the rival.
     */
    private function report(TournamentModel $tournament, bool $disputed = false): void
    {
        $round = RoundModel::query()->firstOrCreate(
            ['tournament_id' => $tournament->id, 'round_number' => 1],
            ['status' => 'in_progress'],
        );
        $players = [];
        for ($i = 0; $i < 2; $i++) {
            $players[] = ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'user_id' => UserModel::factory()->create()->id,
                'status' => ParticipantStatus::Confirmed->value,
                'registered_at' => now(),
            ]);
        }
        $match = MatchModel::create([
            'round_id' => $round->id,
            'player_1_id' => $players[0]->id,
            'player_2_id' => $players[1]->id,
            'result' => 'not_played',
        ]);

        $report = new MatchReport(
            matchId: $match->id,
            result: MatchResult::PlayerOneWin,
            reportedById: (string) $players[0]->user_id,
            reportedAt: new DateTimeImmutable,
        );
        if ($disputed) {
            $report->dispute((string) $players[1]->user_id, 'It was a draw', new DateTimeImmutable);
        }
        $this->reports()->save($report);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return $this->app->make(MatchReportRepositoryInterface::class);
    }
}
