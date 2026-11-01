<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\MatchReportOutcome;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\DTOs\ReportMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchReportingServiceInterface;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Domain\Entities\MatchReport;
use Modules\Tournaments\Domain\Enums\MatchHistoryAction;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Events\MatchResultConfirmed;
use Modules\Tournaments\Domain\Events\MatchResultDisputed;
use Modules\Tournaments\Domain\Events\MatchResultReported;
use Modules\Tournaments\Domain\Exceptions\InvalidMatchResultException;
use Modules\Tournaments\Domain\Exceptions\MatchReportClosedException;
use Modules\Tournaments\Domain\Exceptions\ResultNotConfirmedException;
use Modules\Tournaments\Domain\Exceptions\UnauthorizedToReportResultException;
use Modules\Tournaments\Domain\Repositories\MatchReportRepositoryInterface;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchHistoryModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\StandingModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Services\MatchReportingService;
use Modules\Tournaments\Tests\Support\AssertsContainerBindings;
use Modules\Tournaments\Tests\Support\PinsTheClock;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Players report, correct, confirm and dispute their results; the organization confirms.
 */
final class MatchReportingServiceTest extends ModuleTestCase
{
    use AssertsContainerBindings;
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
        Event::fake([MatchResultReported::class, MatchResultConfirmed::class, MatchResultDisputed::class]);

        $this->tournament = $this->tournament('players_with_confirmation');
        $this->round = RoundModel::create([
            'tournament_id' => $this->tournament->id,
            'round_number' => 1,
            'status' => 'in_progress',
        ]);
        $this->one = UserModel::factory()->create();
        $this->two = UserModel::factory()->create();
        $this->match = $this->match($this->participant($this->one), $this->participant($this->two));
    }

    public function test_the_container_binds_the_service(): void
    {
        $this->assertContainerBinds(MatchReportingServiceInterface::class, MatchReportingService::class);
    }

    public function test_trusted_report_becomes_official_and_recalculates_standings(): void
    {
        $this->tournament->update(['result_reporting' => 'players_trusted']);

        $outcome = $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));

        $this->assertSame(MatchReportOutcome::Recorded, $outcome);
        $match = $this->match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame($this->one->id, $match->reported_by_id);
        $this->assertNull($match->confirmed_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
        $this->assertSame(1, StandingModel::query()->where('participant_id', $this->match->player_1_id)->value('wins'));
        $this->assertSame(MatchHistoryAction::Reported, MatchHistoryModel::query()->where('match_id', $this->match->id)->sole()->action);
        Event::assertDispatched(MatchResultReported::class, fn (MatchResultReported $event): bool => $event->matchId === $this->match->id
            && $event->reportedByUserId === $this->one->id
            && ! $event->requiresConfirmation);
    }

    public function test_confirmation_report_waits_and_counts_as_unreported(): void
    {
        $outcome = $this->service()->report($this->dto(MatchResult::PlayerTwoWin, $this->one, 10, 12, ['vp' => 10], ['vp' => 12]));

        $this->assertSame(MatchReportOutcome::AwaitingConfirmation, $outcome);
        $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);
        $this->assertSame(MatchResult::PlayerTwoWin, $report->result());
        $this->assertSame(12, $report->player2Score());
        $this->assertSame(['vp' => 12], $report->player2Stats());
        $this->assertSame($this->one->id, $report->reportedById());
        $this->assertSame(0, MatchHistoryModel::query()->count());
        Event::assertDispatched(MatchResultReported::class, fn (MatchResultReported $event): bool => $event->requiresConfirmation);
    }

    public function test_rival_confirms_and_result_becomes_official_with_both_ids(): void
    {
        $this->service()->report($this->dto(MatchResult::Draw, $this->one, 15, 15));

        $this->service()->confirm($this->match->id, $this->two->id, $this->seen());

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::Draw, $match->result);
        $this->assertSame(15, $match->player_1_score);
        $this->assertSame($this->one->id, $match->reported_by_id);
        $this->assertSame($this->two->id, $match->confirmed_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
        $entry = MatchHistoryModel::query()->where('match_id', $this->match->id)->sole();
        $this->assertSame(MatchHistoryAction::Confirmed, $entry->action);
        $this->assertSame($this->two->id, $entry->changed_by_id);
        Event::assertDispatched(MatchResultConfirmed::class, fn (MatchResultConfirmed $event): bool => $event->confirmedByUserId === $this->two->id);
    }

    public function test_rival_disputes_with_reason(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));

        $this->service()->dispute($this->match->id, $this->two->id, 'We drew the last game', $this->seen());

        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);
        $this->assertTrue($report->isDisputed());
        $this->assertSame('We drew the last game', $report->disputeReason());
        $this->assertSame($this->two->id, $report->disputedById());
        $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
        Event::assertDispatched(MatchResultDisputed::class, fn (MatchResultDisputed $event): bool => $event->disputedByUserId === $this->two->id);
    }

    public function test_reporter_corrects_and_dispute_is_cleared(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
        $this->service()->dispute($this->match->id, $this->two->id, 'No', $this->seen());

        $this->service()->report($this->dto(MatchResult::Draw, $this->one));

        $report = $this->reports()->find($this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);
        $this->assertSame(MatchResult::Draw, $report->result());
        $this->assertFalse($report->isDisputed());
        Event::assertDispatchedTimes(MatchResultReported::class, 2);
    }

    public function test_a_correction_that_changes_nothing_does_not_announce_it_again(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));

        Event::assertDispatchedTimes(MatchResultReported::class, 1);
    }

    public function test_reporter_cannot_confirm_own_report(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));

        $this->expectException(UnauthorizedToReportResultException::class);

        $this->service()->confirm($this->match->id, $this->one->id, $this->seen());
    }

    public function test_the_opponent_cannot_overwrite_a_report_waiting_for_them(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));

        $this->expectException(MatchReportClosedException::class);

        $this->service()->report($this->dto(MatchResult::PlayerTwoWin, $this->two));
    }

    public function test_double_loss_or_bye_from_players_is_rejected(): void
    {
        foreach ([MatchResult::DoubleLoss, MatchResult::Bye, MatchResult::NotPlayed] as $result) {
            try {
                $this->service()->report($this->dto($result, $this->one));
                $this->fail("{$result->value} is not a result players report.");
            } catch (InvalidMatchResultException $exception) {
                $this->assertSame(InvalidMatchResultException::NOT_A_PLAYER_RESULT, $exception->reason());
            }
        }

        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_scores_that_contradict_the_reported_result_are_rejected(): void
    {
        $this->expectException(InvalidMatchResultException::class);

        try {
            $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 10, 20));
        } finally {
            $this->assertNull($this->reports()->find($this->match->id));
        }
    }

    public function test_a_stranger_cannot_report(): void
    {
        $this->expectException(UnauthorizedToReportResultException::class);

        $this->service()->report($this->dto(MatchResult::PlayerOneWin, UserModel::factory()->create()));
    }

    public function test_organizer_confirms_a_disputed_report(): void
    {
        $organizer = UserModel::factory()->create();
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
        $this->service()->dispute($this->match->id, $this->two->id, 'No', $this->seen());

        $this->service()->confirmAsOrganizer($this->match->id, $organizer->id, $this->seen());

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame($this->one->id, $match->reported_by_id);
        $this->assertSame($organizer->id, $match->confirmed_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_organizer_has_nothing_to_confirm_without_a_report(): void
    {
        $this->expectException(ResultNotConfirmedException::class);

        $this->service()->confirmAsOrganizer($this->match->id, UserModel::factory()->create()->id, 'nothing-was-shown');
    }

    public function test_confirm_pending_in_round_skips_disputed(): void
    {
        $three = UserModel::factory()->create();
        $four = UserModel::factory()->create();
        $other = $this->match($this->participant($three), $this->participant($four));
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
        $this->service()->report(new ReportMatchResultDTO($other->id, MatchResult::Draw, $three->id));
        $this->service()->dispute($other->id, $four->id, 'No', $this->seen($other->id));

        $confirmed = $this->service()->confirmPendingInRound($this->round->id, UserModel::factory()->create()->id);

        $this->assertSame(1, $confirmed);
        $this->assertSame(MatchResult::PlayerOneWin, $this->match->refresh()->result);
        $this->assertSame(MatchResult::NotPlayed, $other->refresh()->result);
        $this->assertInstanceOf(MatchReport::class, $this->reports()->find($other->id));
    }

    public function test_events_dispatch_once_after_commit_and_never_on_rollback(): void
    {
        try {
            DB::transaction(function (): void {
                $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
                Event::assertNotDispatched(MatchResultReported::class);

                throw new RuntimeException('Something later in the same transaction failed.');
            });
        } catch (RuntimeException) {
            // Expected
        }

        Event::assertNotDispatched(MatchResultReported::class);
        $this->assertNull($this->reports()->find($this->match->id));

        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));

        Event::assertDispatchedTimes(MatchResultReported::class, 1);
    }

    public function test_second_concurrent_confirmation_fails(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
        $seen = $this->seen();
        // The organization confirmed it while this request waited for the tournament's lock
        $this->whileWaitingForTheLock(fn () => $this->service()->confirmAsOrganizer($this->match->id, UserModel::factory()->create()->id, $seen));

        try {
            $this->service()->confirm($this->match->id, $this->two->id, $seen);
            $this->fail('The second confirmation must see the result already confirmed.');
        } catch (MatchReportClosedException) {
            Event::assertDispatchedTimes(MatchResultConfirmed::class, 1);
            $this->assertNotSame($this->two->id, $this->match->refresh()->confirmed_by_id);
        }
    }

    public function test_confirming_a_report_changed_since_it_was_seen_fails(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 10));
        $seen = $this->seen();
        // The reporter changed it while the opponent was looking at the first version
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 30, 10));

        try {
            $this->service()->confirm($this->match->id, $this->two->id, $seen);
            $this->fail('A report must be confirmed as the opponent saw it.');
        } catch (ResultNotConfirmedException) {
            $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
            Event::assertNotDispatched(MatchResultConfirmed::class);
        }

        $this->expectException(ResultNotConfirmedException::class);

        $this->service()->dispute($this->match->id, $this->two->id, 'No', $seen);
    }

    public function test_organizer_confirming_a_report_changed_since_it_was_shown_fails(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 10));
        $seen = $this->seen();
        $this->service()->report($this->dto(MatchResult::PlayerTwoWin, $this->one, 10, 20));

        $this->expectException(ResultNotConfirmedException::class);

        try {
            $this->service()->confirmAsOrganizer($this->match->id, UserModel::factory()->create()->id, $seen);
        } finally {
            $this->assertSame(MatchResult::NotPlayed, $this->match->refresh()->result);
        }
    }

    public function test_organizer_writes_take_the_tournament_lock(): void
    {
        $inner = app(RegistrationLockInterface::class);
        $spy = new class($inner) implements RegistrationLockInterface
        {
            /** @var list<string> */
            public array $locked = [];

            public function __construct(private readonly RegistrationLockInterface $inner) {}

            public function forTournament(string $tournamentId, Closure $callback): mixed
            {
                $this->locked[] = $tournamentId;

                return $this->inner->forTournament($tournamentId, $callback);
            }

            public function forParticipant(string $participantId, Closure $callback): mixed
            {
                return $this->inner->forParticipant($participantId, $callback);
            }

            public function forConfirmationToken(string $token, Closure $callback): mixed
            {
                return $this->inner->forConfirmationToken($token, $callback);
            }
        };
        $this->app->instance(RegistrationLockInterface::class, $spy);

        app(MatchResultServiceInterface::class)->recordResult(new RecordMatchResultDTO($this->match->id, MatchResult::Draw, UserModel::factory()->create()->id));

        $this->assertSame([$this->tournament->id], $spy->locked);
    }

    public function test_organizer_cannot_confirm_a_report_over_an_official_result(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one));
        $seen = $this->seen();
        // A leftover: the match got its result some other way
        $this->match->update(['result' => MatchResult::Draw->value]);

        $this->expectException(MatchReportClosedException::class);

        try {
            $this->service()->confirmAsOrganizer($this->match->id, UserModel::factory()->create()->id, $seen);
        } finally {
            $this->assertSame(MatchResult::Draw, $this->match->refresh()->result);
        }
    }

    public function test_after_dropping_the_confirmation_the_rival_reports_over_the_report_left(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));
        $this->tournament->update(['result_reporting' => 'players_trusted']);

        $outcome = $this->service()->report($this->dto(MatchResult::Draw, $this->two, 15, 15));

        $this->assertSame(MatchReportOutcome::Recorded, $outcome);
        $match = $this->match->refresh();
        $this->assertSame(MatchResult::Draw, $match->result);
        $this->assertSame($this->two->id, $match->reported_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_after_dropping_the_confirmation_the_rival_confirms_the_report_left(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));
        $this->tournament->update(['result_reporting' => 'players_trusted']);

        $this->service()->confirm($this->match->id, $this->two->id, $this->seen());

        $match = $this->match->refresh();
        $this->assertSame(MatchResult::PlayerOneWin, $match->result);
        $this->assertSame($this->two->id, $match->confirmed_by_id);
        $this->assertNull($this->reports()->find($this->match->id));
    }

    public function test_after_dropping_the_confirmation_the_rival_cannot_dispute(): void
    {
        $this->service()->report($this->dto(MatchResult::PlayerOneWin, $this->one, 20, 15));
        $this->tournament->update(['result_reporting' => 'players_trusted']);

        $this->expectException(UnauthorizedToReportResultException::class);

        $this->service()->dispute($this->match->id, $this->two->id, 'It was a draw', $this->seen());
    }

    /**
     * What the opponent saw of the report waiting for them.
     */
    private function seen(?string $matchId = null): string
    {
        $report = $this->reports()->find($matchId ?? $this->match->id);
        $this->assertInstanceOf(MatchReport::class, $report);

        return $report->fingerprint();
    }

    /**
     * @param  array<string, mixed>|null  $stats1
     * @param  array<string, mixed>|null  $stats2
     */
    private function dto(MatchResult $result, UserModel $by, ?int $score1 = null, ?int $score2 = null, ?array $stats1 = null, ?array $stats2 = null): ReportMatchResultDTO
    {
        return new ReportMatchResultDTO(
            matchId: $this->match->id,
            result: $result,
            reportedById: $by->id,
            player1Score: $score1,
            player2Score: $score2,
            player1Stats: $stats1,
            player2Stats: $stats2,
        );
    }

    /**
     * Run the change once the tournament's lock is requested and before it is granted (only once).
     */
    private function whileWaitingForTheLock(Closure $change): void
    {
        $inner = app(RegistrationLockInterface::class);

        $this->app->instance(RegistrationLockInterface::class, new class($inner, $change) implements RegistrationLockInterface
        {
            private ?Closure $pending;

            public function __construct(private readonly RegistrationLockInterface $inner, Closure $change)
            {
                $this->pending = $change;
            }

            public function forTournament(string $tournamentId, Closure $callback): mixed
            {
                if ($this->pending instanceof Closure) {
                    $change = $this->pending;
                    $this->pending = null;
                    $change();
                }

                return $this->inner->forTournament($tournamentId, $callback);
            }

            public function forParticipant(string $participantId, Closure $callback): mixed
            {
                return $this->inner->forParticipant($participantId, $callback);
            }

            public function forConfirmationToken(string $token, Closure $callback): mixed
            {
                return $this->inner->forConfirmationToken($token, $callback);
            }
        });
    }

    private function tournament(string $mode): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.Str::random(6),
            'status' => 'in_progress',
            'result_reporting' => $mode,
            'scoring_rules' => [
                ['name' => 'Victoria', 'points' => 3, 'priority' => 0, 'condition' => ['type' => 'result', 'result_value' => 'win', 'stat' => null, 'operator' => null, 'value' => null]],
            ],
        ]);
    }

    private function participant(UserModel $user): ParticipantModel
    {
        return ParticipantModel::create([
            'tournament_id' => $this->tournament->id,
            'user_id' => $user->id,
            'status' => 'confirmed',
        ]);
    }

    private function match(ParticipantModel $one, ParticipantModel $two): MatchModel
    {
        return MatchModel::create([
            'round_id' => $this->round->id,
            'player_1_id' => $one->id,
            'player_2_id' => $two->id,
            'result' => 'not_played',
        ]);
    }

    private function service(): MatchReportingServiceInterface
    {
        return app(MatchReportingServiceInterface::class);
    }

    private function reports(): MatchReportRepositoryInterface
    {
        return app(MatchReportRepositoryInterface::class);
    }
}
