<?php

declare(strict_types=1);

namespace Modules\Tournaments\Tests\Integration\Infrastructure\Services;

use App\Infrastructure\Persistence\Eloquent\Models\EventModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tournaments\Application\DTOs\RegisterParticipantDTO;
use Modules\Tournaments\Domain\Enums\ParticipantStatus;
use Modules\Tournaments\Domain\Enums\TournamentStatus;
use Modules\Tournaments\Domain\Exceptions\AlreadyRegisteredException;
use Modules\Tournaments\Domain\Exceptions\CannotWithdrawException;
use Modules\Tournaments\Domain\Exceptions\CheckInWindowClosedException;
use Modules\Tournaments\Domain\Exceptions\ParticipantNotFoundException;
use Modules\Tournaments\Domain\Exceptions\TournamentFullException;
use Modules\Tournaments\Domain\Exceptions\TournamentNotOpenException;
use Modules\Tournaments\Domain\Exceptions\UserNotAllowedToRegisterException;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\ParticipantModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Models\TournamentModel;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentParticipantRepository;
use Modules\Tournaments\Infrastructure\Persistence\Eloquent\Repositories\EloquentTournamentRepository;
use Modules\Tournaments\Infrastructure\Services\ParticipantManagementService;
use Modules\Tournaments\Infrastructure\Services\UserDataProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Each rejected registration or withdrawal must raise the domain exception the
 * controllers turn into a message, never a PHP error (500). Guest registrations: GuestRegistrationTest.
 */
final class ParticipantRegistrationRulesTest extends TestCase
{
    use RefreshDatabase;

    private ParticipantManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ParticipantManagementService(
            new EloquentTournamentRepository,
            new EloquentParticipantRepository,
            new UserDataProvider,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tournament(array $attributes = []): TournamentModel
    {
        return TournamentModel::create([
            'event_id' => EventModel::factory()->create()->id,
            'name' => 'Torneo',
            'slug' => 'torneo-'.bin2hex(random_bytes(4)),
            'status' => TournamentStatus::RegistrationOpen->value,
            'result_reporting' => 'admin_only',
            'allow_guests' => true,
            'notification_email' => 'test@example.com',
            ...$attributes,
        ]);
    }

    private function participant(TournamentModel $tournament, array $attributes = []): ParticipantModel
    {
        return ParticipantModel::create([
            'id' => fake()->uuid(),
            'tournament_id' => $tournament->id,
            'status' => ParticipantStatus::Registered->value,
            'registered_at' => new DateTimeImmutable,
            ...$attributes,
        ]);
    }

    private function eventStartingAt(DateTimeInterface $startsAt): string
    {
        return EventModel::factory()->create(['start_date' => $startsAt])->id;
    }

    /**
     * @return iterable<string, array{TournamentStatus, string}>
     */
    public static function closedStatuses(): iterable
    {
        yield 'draft' => [TournamentStatus::Draft, 'not yet open'];
        yield 'registration closed' => [TournamentStatus::RegistrationClosed, 'already closed'];
        yield 'in progress' => [TournamentStatus::InProgress, 'in progress'];
        yield 'finished' => [TournamentStatus::Finished, 'has finished'];
        yield 'cancelled' => [TournamentStatus::Cancelled, 'cancelled'];
    }

    #[DataProvider('closedStatuses')]
    public function test_registering_when_registration_is_not_open_says_why(TournamentStatus $status, string $reason): void
    {
        $tournament = $this->tournament(['status' => $status->value]);

        $this->expectException(TournamentNotOpenException::class);
        $this->expectExceptionMessage($reason);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_registering_in_a_full_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(TournamentFullException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_a_user_without_an_allowed_role_cannot_register(): void
    {
        $tournament = $this->tournament(['allowed_roles' => ['socio']]);

        $this->expectException(UserNotAllowedToRegisterException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_a_returning_withdrawn_user_without_an_allowed_role_is_rejected(): void
    {
        $tournament = $this->tournament(['allowed_roles' => ['socio']]);
        $user = UserModel::factory()->create();
        $withdrawn = $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::Withdrawn->value]);

        try {
            $this->service->register(new RegisterParticipantDTO($tournament->id, $user->id));
            $this->fail('A withdrawn user without the role came back');
        } catch (UserNotAllowedToRegisterException) {
            $this->assertSame(ParticipantStatus::Withdrawn, $withdrawn->fresh()?->status);
        }
    }

    public function test_an_active_user_in_a_full_tournament_is_told_they_are_already_registered(): void
    {
        $tournament = $this->tournament(['max_participants' => 1]);
        $user = UserModel::factory()->create();
        $this->participant($tournament, ['user_id' => $user->id]);

        $this->expectException(AlreadyRegisteredException::class);

        $this->service->register(new RegisterParticipantDTO($tournament->id, $user->id));
    }

    public function test_withdrawing_from_a_finished_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::Finished->value]);
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('finished');

        $this->service->withdraw($participant->id);
    }

    public function test_withdrawing_by_token_from_a_finished_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::Finished->value]);
        $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'cancellation_token' => 'finished-token']);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('finished');

        $this->service->withdrawByToken('finished-token');
    }

    public function test_withdrawing_twice_by_token_is_rejected(): void
    {
        $tournament = $this->tournament();
        $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'cancellation_token' => 'twice-token']);
        $this->service->withdrawByToken('twice-token');

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('already withdrawn');

        $this->service->withdrawByToken('twice-token');
    }

    public function test_withdrawing_twice_is_rejected(): void
    {
        $tournament = $this->tournament();
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);
        $this->service->withdraw($participant->id);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('already withdrawn');

        $this->service->withdraw($participant->id);
    }

    public function test_a_disqualified_participant_cannot_withdraw(): void
    {
        $tournament = $this->tournament();
        $participant = $this->participant($tournament, [
            'user_id' => UserModel::factory()->create()->id,
            'status' => ParticipantStatus::Disqualified->value,
        ]);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('disqualified');

        $this->service->withdraw($participant->id);
    }

    public function test_a_disqualified_user_cannot_register_again(): void
    {
        $tournament = $this->tournament();
        $user = UserModel::factory()->create();
        $this->participant($tournament, ['user_id' => $user->id, 'status' => ParticipantStatus::Disqualified->value]);

        $this->expectException(AlreadyRegisteredException::class);
        $this->expectExceptionMessage('disqualified');

        $this->service->register(new RegisterParticipantDTO($tournament->id, $user->id));
    }

    public function test_registering_once_the_event_has_started_is_rejected_even_while_registration_is_open(): void
    {
        $tournament = $this->tournament(['event_id' => $this->eventStartingAt(now()->subMinute())]);

        try {
            $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
            $this->fail('A user registered after the event started');
        } catch (TournamentNotOpenException $e) {
            $this->assertTrue($e->eventHasStarted());
            $this->assertStringContainsString('event started', $e->getMessage());
        }

        $this->assertSame(0, ParticipantModel::query()->where('tournament_id', $tournament->id)->count());
    }

    public function test_registering_before_the_opening_date_is_rejected(): void
    {
        $tournament = $this->tournament(['registration_opens_at' => now()->addDay()]);

        $this->expectException(TournamentNotOpenException::class);
        $this->expectExceptionMessage('not yet open');

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_registering_after_the_closing_date_is_rejected(): void
    {
        $tournament = $this->tournament(['registration_closes_at' => now()->subMinute()]);

        $this->expectException(TournamentNotOpenException::class);
        $this->expectExceptionMessage('already closed');

        $this->service->register(new RegisterParticipantDTO($tournament->id, UserModel::factory()->create()->id));
    }

    public function test_withdrawing_once_the_event_has_started_is_rejected_even_while_registration_is_open(): void
    {
        $tournament = $this->tournament(['event_id' => $this->eventStartingAt(now()->subMinute())]);
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        try {
            $this->service->withdraw($participant->id);
            $this->fail('A participant withdrew after the event started');
        } catch (CannotWithdrawException $e) {
            $this->assertTrue($e->eventHasStarted());
        }

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_withdrawing_by_token_once_the_event_has_started_is_rejected(): void
    {
        $tournament = $this->tournament([
            'event_id' => $this->eventStartingAt(now()->subMinute()),
            'status' => TournamentStatus::RegistrationClosed->value,
        ]);
        $participant = $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com', 'cancellation_token' => 'started-token']);

        try {
            $this->service->withdrawByToken('started-token');
            $this->fail('A guest withdrew by token after the event started');
        } catch (CannotWithdrawException $e) {
            $this->assertTrue($e->eventHasStarted());
        }

        $this->assertSame(ParticipantStatus::Registered, $participant->fresh()?->status);
    }

    public function test_withdrawing_after_registration_closes_is_allowed_until_the_event_starts(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::RegistrationClosed->value]);
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $result = $this->service->withdraw($participant->id);

        $this->assertSame(ParticipantStatus::Withdrawn, $result->status);
    }

    public function test_withdrawing_from_a_cancelled_tournament_is_rejected(): void
    {
        $tournament = $this->tournament(['status' => TournamentStatus::Cancelled->value]);
        $participant = $this->participant($tournament, ['user_id' => UserModel::factory()->create()->id]);

        $this->expectException(CannotWithdrawException::class);
        $this->expectExceptionMessage('cancelled');

        $this->service->withdraw($participant->id);
    }

    public function test_checking_in_inside_the_window_still_works(): void
    {
        $tournament = $this->tournament([
            'event_id' => $this->eventStartingAt(now()->addMinutes(10)),
            'status' => TournamentStatus::RegistrationClosed->value,
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);
        $this->participant($tournament, [
            'guest_name' => 'Ana',
            'guest_email' => 'ana@example.com',
            'status' => ParticipantStatus::Confirmed->value,
            'email_confirmed_at' => now()->subDay(),
        ]);

        $result = $this->service->checkInByEmail($tournament->id, 'ana@example.com');

        $this->assertSame(ParticipantStatus::CheckedIn, $result->status);
    }

    public function test_checking_in_after_the_event_start_is_rejected(): void
    {
        $tournament = $this->tournament([
            'event_id' => $this->eventStartingAt(now()->subMinute()),
            'status' => TournamentStatus::RegistrationClosed->value,
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
        ]);
        $this->participant($tournament, ['guest_name' => 'Ana', 'guest_email' => 'ana@example.com']);

        $this->expectException(CheckInWindowClosedException::class);
        $this->expectExceptionMessage('has closed');

        $this->service->checkInByEmail($tournament->id, 'ana@example.com');
    }

    public function test_an_email_not_found_at_check_in_is_not_written_in_the_message(): void
    {
        $tournament = $this->tournament([
            'event_id' => $this->eventStartingAt(now()->addMinutes(10)),
            'status' => TournamentStatus::RegistrationClosed->value,
            'requires_check_in' => true,
            'self_check_in_allowed' => true,
            'check_in_starts_before' => 30,
        ]);

        try {
            $this->service->checkInByEmail($tournament->id, 'nadie@example.com');
            $this->fail('An unknown email checked in');
        } catch (ParticipantNotFoundException $e) {
            $this->assertStringNotContainsString('nadie@example.com', $e->getMessage());
            $this->assertStringContainsString($tournament->id, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{ParticipantStatus, bool, bool}>
     */
    public static function checkInRule(): iterable
    {
        yield 'registered, no manual confirmation' => [ParticipantStatus::Registered, false, true];
        yield 'confirmed, no manual confirmation' => [ParticipantStatus::Confirmed, false, true];
        yield 'registered, manual confirmation' => [ParticipantStatus::Registered, true, false];
        yield 'confirmed, manual confirmation' => [ParticipantStatus::Confirmed, true, true];
        yield 'checked in' => [ParticipantStatus::CheckedIn, false, false];
        yield 'withdrawn' => [ParticipantStatus::Withdrawn, false, false];
        yield 'expired' => [ParticipantStatus::Expired, false, false];
        yield 'disqualified' => [ParticipantStatus::Disqualified, true, false];
    }

    #[DataProvider('checkInRule')]
    public function test_the_check_in_rule_shared_by_the_panel_and_self_check_in(ParticipantStatus $status, bool $manualConfirmation, bool $allowed): void
    {
        $this->assertSame($allowed, $this->service->canCheckIn($status, $manualConfirmation));
    }
}
