<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Tournaments\Application\Services\TournamentLifecycleServiceInterface;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Events\TournamentStarted;
use Modules\Tournaments\Domain\Exceptions\InsufficientParticipantsException;
use Modules\Tournaments\Domain\Exceptions\InvalidStateTransitionException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use RuntimeException;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Opening and closing registration and starting go through the tournament's state machine,
 * with its row locked, and the start is announced once and after the commit.
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
}
