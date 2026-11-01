<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\DTOs\RecordMatchResultDTO;
use Modules\Tournaments\Application\Services\MatchResultServiceInterface;
use Modules\Tournaments\Application\Services\RegistrationLockInterface;
use Modules\Tournaments\Application\Services\RoundManagementServiceInterface;
use Modules\Tournaments\Domain\Enums\MatchResult;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\RoundStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\RoundCompleted;
use Modules\Tournaments\Domain\Events\RoundGenerated;
use Modules\Tournaments\Domain\Events\RoundStarted;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Domain\Exceptions\PreviousRoundNotCompletedException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\MatchModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\RoundModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Generating, starting and completing a round announce it once, after the commit.
 */
final class RoundEventsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'tournaments';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoundGenerated::class, RoundStarted::class, RoundCompleted::class]);
    }

    public function test_generating_a_round_announces_it_once_with_its_matches_and_bye(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 5);

        $round = $this->rounds()->generateNextRound($tournament->id);

        Event::assertDispatchedTimes(RoundGenerated::class, 1);
        Event::assertDispatched(RoundGenerated::class, fn (RoundGenerated $event): bool => $event->roundId === $round->id
            && $event->tournamentId === $tournament->id
            && $event->roundNumber === 1
            && $event->matchCount === 3
            && $event->hasBye);
    }

    public function test_a_round_without_bye_says_so(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);

        $this->rounds()->generateNextRound($tournament->id);

        Event::assertDispatched(RoundGenerated::class, fn (RoundGenerated $event): bool => $event->matchCount === 2 && ! $event->hasBye);
    }

    public function test_a_round_that_cannot_be_generated_announces_nothing(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 1);

        try {
            $this->rounds()->generateNextRound($tournament->id);
            $this->fail('A round was generated with a single participant.');
        } catch (InsufficientParticipantsException) {
            // Expected
        }

        Event::assertNotDispatched(RoundGenerated::class);
    }

    public function test_a_rolled_back_generation_announces_nothing(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);

        $this->rollBackAfter(fn () => $this->rounds()->generateNextRound($tournament->id));

        $this->assertSame(0, RoundModel::query()->count());
        Event::assertNotDispatched(RoundGenerated::class);
    }

    public function test_starting_a_round_announces_it_once(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);

        $this->rounds()->startRound($round->id);

        try {
            $this->rounds()->startRound($round->id);
            $this->fail('A round in progress cannot start again.');
        } catch (InvalidStateTransitionException) {
            // Expected: the second click finds it already started
        }

        Event::assertDispatchedTimes(RoundStarted::class, 1);
        Event::assertDispatched(RoundStarted::class, fn (RoundStarted $event): bool => $event->roundId === $round->id
            && $event->tournamentId === $tournament->id
            && $event->roundNumber === 1);
    }

    public function test_a_round_started_meanwhile_by_someone_else_is_not_announced_again(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);
        // The other request started the round while this one waited for the tournament's lock
        $this->whileWaitingForTheLock(static fn () => RoundModel::query()->whereKey($round->id)->update([
            'status' => RoundStatus::InProgress->value,
            'started_at' => now(),
        ]));

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->rounds()->startRound($round->id);
        } finally {
            Event::assertNotDispatched(RoundStarted::class);
        }
    }

    public function test_a_rolled_back_start_announces_nothing(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);

        $this->rollBackAfter(fn () => $this->rounds()->startRound($round->id));

        $this->assertSame(RoundStatus::Pending, RoundModel::query()->findOrFail($round->id)->status);
        Event::assertNotDispatched(RoundStarted::class);
    }

    public function test_completing_a_round_announces_it_once(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $this->rounds()->startRound($round->id);
        $this->reportAll($round->id);

        $this->rounds()->completeRound($round->id);

        try {
            $this->rounds()->completeRound($round->id);
            $this->fail('A finished round cannot be completed again.');
        } catch (InvalidStateTransitionException) {
            // Expected: the second click finds it already finished
        }

        Event::assertDispatchedTimes(RoundCompleted::class, 1);
        Event::assertDispatched(RoundCompleted::class, fn (RoundCompleted $event): bool => $event->roundId === $round->id
            && $event->tournamentId === $tournament->id
            && $event->roundNumber === 1);
    }

    public function test_a_round_completed_meanwhile_by_someone_else_is_not_announced_again(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $this->rounds()->startRound($round->id);
        $this->reportAll($round->id);
        $this->whileWaitingForTheLock(static fn () => RoundModel::query()->whereKey($round->id)->update([
            'status' => RoundStatus::Finished->value,
            'completed_at' => now(),
        ]));

        $this->expectException(InvalidStateTransitionException::class);

        try {
            $this->rounds()->completeRound($round->id);
        } finally {
            Event::assertNotDispatched(RoundCompleted::class);
        }
    }

    public function test_a_round_with_matches_without_result_announces_nothing(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $this->rounds()->startRound($round->id);

        try {
            $this->rounds()->completeRound($round->id);
            $this->fail('A round with matches without result was completed.');
        } catch (PreviousRoundNotCompletedException) {
            // Expected
        }

        Event::assertNotDispatched(RoundCompleted::class);
    }

    public function test_a_rolled_back_completion_announces_nothing(): void
    {
        $tournament = $this->tournament();
        $this->participants($tournament, 4);
        $round = $this->rounds()->generateNextRound($tournament->id);
        $this->rounds()->startRound($round->id);
        $this->reportAll($round->id);

        $this->rollBackAfter(fn () => $this->rounds()->completeRound($round->id));

        $this->assertSame(RoundStatus::InProgress, RoundModel::query()->findOrFail($round->id)->status);
        Event::assertNotDispatched(RoundCompleted::class);
    }

    /**
     * Run the call inside a transaction that something later makes fail.
     */
    private function rollBackAfter(Closure $call): void
    {
        try {
            DB::transaction(static function () use ($call): void {
                $call();

                throw new RuntimeException('Something later in the same transaction failed.');
            });
        } catch (RuntimeException) {
            // Expected
        }
    }

    /**
     * Run the change once the tournament's lock is requested and before it is granted.
     */
    private function whileWaitingForTheLock(Closure $change): void
    {
        $inner = app(RegistrationLockInterface::class);

        $this->app->instance(RegistrationLockInterface::class, new readonly class($inner, $change) implements RegistrationLockInterface
        {
            public function __construct(private RegistrationLockInterface $inner, private Closure $change) {}

            public function forTournament(string $tournamentId, Closure $callback): mixed
            {
                ($this->change)();

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

    private function rounds(): RoundManagementServiceInterface
    {
        return app(RoundManagementServiceInterface::class);
    }

    private function reportAll(string $roundId): void
    {
        $admin = UserModel::factory()->admin()->create();
        $results = app(MatchResultServiceInterface::class);
        MatchModel::query()->where('round_id', $roundId)->where('result', MatchResult::NotPlayed->value)->get()
            ->each(static fn (MatchModel $m) => $results->recordResult(new RecordMatchResultDTO($m->id, MatchResult::PlayerOneWin, $admin->id)));
    }

    private function tournament(): TournamentModel
    {
        return TournamentModel::create([
            'id' => (string) Str::uuid(),
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo suizo',
            'slug' => 'torneo-suizo-'.Str::random(6),
            'status' => TournamentStatus::InProgress->value,
            'result_reporting' => 'admin_only',
        ])->refresh();
    }

    private function participants(TournamentModel $tournament, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            ParticipantModel::create([
                'id' => (string) Str::uuid(),
                'tournament_id' => $tournament->id,
                'guest_name' => 'Jugador '.Str::random(4),
                'guest_email' => Str::random(8).'@example.com',
                'status' => ParticipantStatus::Confirmed->value,
                'registered_at' => now(),
            ]);
        }
    }
}
